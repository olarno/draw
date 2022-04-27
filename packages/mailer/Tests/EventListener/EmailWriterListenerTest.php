<?php

namespace Draw\Component\Mailer\Tests\EventListener;

use Draw\Component\Mailer\EmailWriter\EmailWriterInterface;
use Draw\Component\Mailer\EventListener\EmailWriterListener;
use Draw\Component\Tester\MockBuilderTrait;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * @covers \Draw\Component\Mailer\EventListener\EmailWriterListener
 */
class EmailWriterListenerTest extends TestCase
{
    use MockBuilderTrait;

    private EmailWriterListener $object;

    private ContainerInterface $serviceLocator;

    public function setUp(): void
    {
        $this->object = new EmailWriterListener(
            $this->serviceLocator = $this->createMock(ContainerInterface::class)
        );
    }

    public function testConstruct(): void
    {
        $this->assertInstanceOf(
            EventSubscriberInterface::class,
            $this->object
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $this->assertSame(
            [
                MessageEvent::class => ['composeMessage', 200],
            ],
            $this->object::getSubscribedEvents()
        );
    }

    public function testWriterMutator(): void
    {
        $this->assertSame([], $this->object->getWriters(stdClass::class));

        $this->object->addWriter(stdClass::class, $writer1 = uniqid('writer-'), $method1 = uniqid('method-'));

        $this->assertSame(
            [],
            $this->object->getWriters(uniqid('other-class-'))
        );

        $this->assertSame(
            [
                [$writer1, $method1],
            ],
            $this->object->getWriters(stdClass::class)
        );

        $this->object->addWriter(stdClass::class, $writer2 = uniqid('writer-'), $method2 = uniqid('method-'), 1);

        $this->assertSame(
            [
                [$writer2, $method2],
                [$writer1, $method1],
            ],
            $this->object->getWriters(stdClass::class)
        );
    }

    public function testComposeMessageNotMessage(): void
    {
        $this->serviceLocator
            ->expects($this->never())
            ->method('get');

        $this->object->composeMessage(
            $this->createMessageEvent(
                $this->createMock(RawMessage::class)
            )
        );
    }

    public function testComposeMessageComposed(): void
    {
        $this->serviceLocator
            ->expects($this->never())
            ->method('get');

        $message = $this->createMock(Message::class);

        $message
            ->expects($this->once())
            ->method('getHeaders')
            ->willReturn($headers = new Headers());

        $headers->add(new UnstructuredHeader('X-DrawEmail', 1));

        $this->object->composeMessage($this->createMessageEvent($message));
    }

    public function testComposeMessage(): void
    {
        $message = $this->createMock(Email::class);

        $message
            ->expects($this->once())
            ->method('getHeaders')
            ->willReturn($headers = new Headers());

        $event = $this->createMessageEvent($message);

        $this->object->addWriter(Message::class, $writer1 = uniqid('writer-1-'), 'method1');
        $this->object->addWriter(Email::class, $writer2 = uniqid('writer-2-'), 'method2');
        $this->object->addWriter(uniqid('other-class-'), uniqid('writer-'), uniqid('method-'));

        $this->serviceLocator
            ->expects($this->exactly(2))
            ->method('get')
            ->withConsecutive(
                [$writer2],
                [$writer1]
            )
            ->willReturn(
                $emailWriter = $this->createMockWithExtraMethods(EmailWriterInterface::class, ['method1', 'method2'])
            );

        $emailWriter
            ->expects($this->once())
            ->method('method1')
            ->with(
                $message,
                $event->getEnvelope()
            );

        $emailWriter
            ->expects($this->once())
            ->method('method2')
            ->with(
                $message,
                $event->getEnvelope()
            );

        $this->object->composeMessage($event);

        $this->assertTrue($headers->has('X-DrawEmail'));
    }

    public function testRegisterEmailWriter(): void
    {
        $message = $this->createMock(Email::class);

        $message
            ->expects($this->once())
            ->method('getHeaders')
            ->willReturn(new Headers());

        $event = $this->createMessageEvent($message);

        $emailWriter = new class() implements EmailWriterInterface {
            public int $compose1CallCounter = 0;

            public int $compose2CallCounter = 0;

            public static function getForEmails(): array
            {
                return [
                    'compose1',
                    'compose2',
                ];
            }

            public function compose1(Email $email): void
            {
                ++$this->compose1CallCounter;
            }

            public function compose2(Message $email): void
            {
                ++$this->compose2CallCounter;
            }
        };

        $this->object->registerEmailWriter($emailWriter);

        $this->assertSame(
            [
                [$emailWriter, 'compose1'],
            ],
            $this->object->getWriters(Email::class)
        );

        $this->assertSame(
            [
                [$emailWriter, 'compose2'],
            ],
            $this->object->getWriters(Message::class)
        );

        $this->serviceLocator
            ->expects($this->never())
            ->method('get');

        $this->object->composeMessage($event);

        $this->assertSame(1, $emailWriter->compose1CallCounter);
        $this->assertSame(1, $emailWriter->compose2CallCounter);
    }

    private function createMessageEvent(RawMessage $message): MessageEvent
    {
        return new MessageEvent(
            $message,
            new Envelope(new Address('test@example.com'), [new Address('test@example.com')]),
            uniqid('transport-')
        );
    }
}