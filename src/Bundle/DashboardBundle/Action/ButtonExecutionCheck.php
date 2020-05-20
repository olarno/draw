<?php namespace Draw\Bundle\DashboardBundle\Action;

use Draw\Bundle\DashboardBundle\Annotations\Action;
use Draw\Bundle\DashboardBundle\Annotations\Button\Button;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @internal
 */
final class ButtonExecutionCheck
{
    private $actionFinder;

    private $authorizationChecker;

    public function __construct(
        AuthorizationCheckerInterface $authorizationChecker,
        ActionFinder $actionFinder
    )
    {
        $this->authorizationChecker = $authorizationChecker;
        $this->actionFinder = $actionFinder;
    }

    public function canExecute(Button $button, ?Action $action)
    {
        if(!$action) {
            return false;
        }

        if(!($thenList = $this->getThenBehaviours($button))) {
            return true;
        }

        $targets = $action->getTargets();

        if(!$targets) {
            return false;
        }

        foreach($thenList as $thenActionType) {
            foreach ($this->actionFinder->findAllByByTarget($targets[0]) as $action) {
                if($action->getType() !== $thenActionType) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private function getThenBehaviours(Button $button): array
    {
        $thenList = [];
        foreach($button->getBehaviours() as $behaviour) {
            if (strpos($behaviour, 'then-') !== 0) {
                continue;
            }

            $thenList[] = substr($behaviour, strlen('then-'));
        }
        return $thenList;
    }
}