<?php

namespace Draw\Component\Console\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception as DBALException;
use Draw\Component\Console\Entity\Execution;
use Draw\Component\Console\Event\CommandErrorEvent;
use Draw\Component\Console\Event\LoadExecutionIdEvent;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Event;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CommandFlowListener implements EventSubscriberInterface
{
    final public const OPTION_EXECUTION_ID = 'draw-execution-id';

    final public const OPTION_IGNORE = 'draw-execution-ignore';

    private array $commandsToIgnore = [
        'help',
        'doctrine:database:drop',
        'doctrine:database:create',
        'cache:clear',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            LoadExecutionIdEvent::class => [
                ['checkIgnoredCommands'],
                ['checkHelp'],
                ['checkTableExist'],
                ['loadIdFromInput', -10],
                ['generateFromDatabase', -10],
            ],
            Event\ConsoleCommandEvent::class => [
                ['configureOptions', 1],
                ['logCommandStart', 0],
            ],
            Event\ConsoleTerminateEvent::class => ['logCommandTerminate'],
            Event\ConsoleErrorEvent::class => ['logCommandError'],
        ];
    }

    public function __construct(
        private Connection $connection,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private bool $ignoreDisabledCommand = false
    ) {
    }

    public function configureOptions(Event\ConsoleCommandEvent $event): void
    {
        $definition = $event->getCommand()->getDefinition();

        if (!$definition->hasOption(static::OPTION_IGNORE)) {
            $definition->addOption(
                new InputOption(
                    self::OPTION_IGNORE,
                    null,
                    InputOption::VALUE_NONE,
                    'Flag to ignore login of the execution to the databases.',
                )
            );
        }

        if (!$definition->hasOption(static::OPTION_EXECUTION_ID)) {
            $definition->addOption(
                new InputOption(
                    self::OPTION_EXECUTION_ID,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'The existing execution id of the command. Use internally by draw/console.',
                )
            );
        }
    }

    public function checkIgnoredCommands(LoadExecutionIdEvent $event): void
    {
        if (\in_array($event->getCommand()->getName(), $this->commandsToIgnore)) {
            $event->ignoreTracking();
        }
    }

    public function checkHelp(LoadExecutionIdEvent $event): void
    {
        $input = $event->getInput();
        if ($input->hasOption('help') && $input->getOption('help')) {
            $event->ignoreTracking();
        }
    }

    public function checkTableExist(LoadExecutionIdEvent $event): void
    {
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(['command__execution'])) {
                $event->ignoreTracking();
            }
        } catch (DBALException) {
            $event->ignoreTracking();
        }
    }

    public function loadIdFromInput(LoadExecutionIdEvent $event): void
    {
        if (null !== $executionId = $this->getExecutionId($event)) {
            $event->setExecutionId($executionId);
        }
    }

    public function generateFromDatabase(LoadExecutionIdEvent $event): void
    {
        $reconnectToSlave = $this->mustReconnectToSlave();

        $input = $event->getInput();
        $parameters = $input->getArguments();

        // We want to keep 0 value
        $options = array_filter($input->getOptions(), fn ($value) => false !== $value && null !== $value);

        foreach ($options as $key => $value) {
            $parameters['--'.$key] = $value;
        }

        $date = date('Y-m-d H:i:s');

        $executionId = Uuid::uuid6()->toString();

        try {
            $this->connection->insert(
                'command__execution',
                [
                    'id' => $executionId,
                    'command_name' => $event->getCommand()->getName(),
                    'created_at' => $date,
                    'updated_at' => $date,
                    'output' => '',
                    'state' => Execution::STATE_STARTED,
                    'input' => json_encode($parameters, \JSON_THROW_ON_ERROR),
                ]
            );
        } catch (\Throwable $error) {
            $this->logger->error('Command flow listener error while generating execution id', ['error' => $error]);
            $event->ignoreTracking();

            return;
        } finally {
            if ($reconnectToSlave && $this->connection instanceof PrimaryReadReplicaConnection) {
                $this->connection->ensureConnectedToReplica();
            }
        }

        $event->setExecutionId($executionId);
    }

    public function logCommandStart(Event\ConsoleCommandEvent $event): void
    {
        if ($event->getInput()->getOption(self::OPTION_IGNORE)) {
            return;
        }

        $executionId = $this->eventDispatcher->dispatch(new LoadExecutionIdEvent(
            $event->getCommand(),
            $event->getInput(),
            $event->getOutput()
        ))->getExecutionId();

        if (null === $executionId) {
            return;
        }

        $option = $event->getCommand()->getDefinition()->getOption(self::OPTION_EXECUTION_ID);
        $option->setDefault($executionId);

        $this->updateState($executionId, Execution::STATE_STARTED);
    }

    public function logCommandTerminate(Event\ConsoleTerminateEvent $event): void
    {
        if (null === $executionId = $this->getExecutionId($event)) {
            return;
        }

        $output = $event->getOutput();
        $outputString = null;
        if (method_exists($output, 'fetch')) {
            $outputString = $output->fetch();
        }

        $state = ConsoleCommandEvent::RETURN_CODE_DISABLED === $event->getExitCode()
            ? Execution::STATE_DISABLED
            : Execution::STATE_TERMINATED;

        $this->updateState($executionId, $state, $outputString);
    }

    public function logCommandError(Event\ConsoleErrorEvent $event): void
    {
        if (null === $executionId = $this->getExecutionId($event)) {
            return;
        }

        $error = $event->getError();

        $outputString = '';

        switch (true) {
            case null === $command = $event->getCommand():
            case null === $application = $command->getApplication():
                break;
            default:
                $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG, true);
                $application->renderThrowable($error, $output);
                $outputString = $output->fetch();
        }

        $commandErrorEvent = $this->eventDispatcher
            ->dispatch(new CommandErrorEvent($executionId, $outputString));

        $executionState = $commandErrorEvent->isAutoAcknowledge()
            ? Execution::STATE_AUTO_ACKNOWLEDGE
            : Execution::STATE_ERROR;

        $this->updateState(
            $executionId,
            $executionState,
            $outputString,
            $commandErrorEvent->getAutoAcknowledgeReason()
        );
    }

    private function getExecutionId(Event\ConsoleEvent $event): ?string
    {
        $input = $event->getInput();
        if (!$input->hasOption(self::OPTION_EXECUTION_ID)) {
            return null;
        }

        return $input->getOption(self::OPTION_EXECUTION_ID);
    }

    private function updateState(
        string $executionId,
        string $state,
        ?string $outputString = null,
        ?string $autoAcknowledgeReason = null
    ): void {
        $reconnectToSlave = $this->mustReconnectToSlave();

        if ($this->ignoreDisabledCommand && Execution::STATE_DISABLED === $state) {
            $this->connection
                ->prepare('DELETE FROM command__execution WHERE id = :id')
                ->executeStatement(['id' => $executionId]);

            if ($reconnectToSlave && $this->connection instanceof PrimaryReadReplicaConnection) {
                $this->connection->ensureConnectedToReplica();
            }

            return;
        }

        if (mb_strlen((string) $outputString) > 50000) {
            $outputString = sprintf(
                "%s\n\n[OUTPUT WAS TOO BIG]\n\nTail of log:\n\n%s",
                mb_substr($outputString, 0, 40000),
                mb_substr($outputString, -10000)
            );
        }

        $date = date('Y-m-d H:i:s');
        $parameters = [
            'id' => $executionId,
            'updated_at' => $date,
            'state' => $state,
        ];

        $setOutput = null;
        if ($outputString) {
            $setOutput = 'output = TRIM(CONCAT_WS(" ", output, :output)),';
            $parameters['output'] = $outputString;
        }

        $setAutoAcknowledgeReason = null;
        if ($autoAcknowledgeReason) {
            $parameters['auto_acknowledge_reason'] = $autoAcknowledgeReason;
            $setAutoAcknowledgeReason = 'auto_acknowledge_reason = :auto_acknowledge_reason,';
        }

        $query = <<<SQL
            UPDATE
              command__execution
            SET
              updated_at = :updated_at,
              $setOutput
              $setAutoAcknowledgeReason
              state = IF(state != 'error', :state, state)
            WHERE
            id = :id
            SQL;

        $this->connection->prepare($query)->executeStatement($parameters);

        if ($reconnectToSlave && $this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToReplica();
        }
    }

    private function mustReconnectToSlave(): bool
    {
        return $this->connection instanceof PrimaryReadReplicaConnection && !$this->connection->isConnectedToPrimary();
    }
}
