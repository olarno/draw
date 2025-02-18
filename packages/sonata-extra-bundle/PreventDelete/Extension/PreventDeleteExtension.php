<?php

namespace Draw\Bundle\SonataExtraBundle\PreventDelete\Extension;

use Doctrine\Persistence\ManagerRegistry;
use Draw\Bundle\SonataExtraBundle\PreventDelete\PreventDelete;
use Draw\Bundle\SonataExtraBundle\PreventDelete\PreventDeleteRelationLoader;
use Sonata\AdminBundle\Admin\AbstractAdminExtension;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Show\ShowMapper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Security\Core\Security;

#[AutoconfigureTag(
    'sonata.admin.extension',
    [
        'global' => true,
    ]
)]
class PreventDeleteExtension extends AbstractAdminExtension
{
    public function __construct(
        private PreventDeleteRelationLoader $preventDeleteRelationLoader,
        private ManagerRegistry $managerRegistry,
        private Security $security,
        private ?string $restrictToRole = null
    ) {
    }

    public function configureShowFields(ShowMapper $show): void
    {
        if ($this->restrictToRole && !$this->security->isGranted($this->restrictToRole)) {
            return;
        }

        $admin = $show->getAdmin();

        if (!$admin->hasRoute('delete')) {
            return;
        }

        $subject = $admin->getSubject();

        $relations = $this->preventDeleteRelationLoader->getRelationsForObject($subject);

        if (empty($relations)) {
            return;
        }

        $admin = $show->getAdmin();

        while ($show->hasOpenTab()) {
            $show->end();
        }

        $show
            ->tab('prevent_deletions');

        $configurationPool = $admin->getConfigurationPool();

        foreach ($relations as $relation) {
            $metadata = $relation->getMetadata();

            $maxResult = $metadata['max_results'] ?? 10;
            $subject = $admin->getSubject();
            $relatedEntities = $relation->getEntities(
                $this->managerRegistry,
                $subject,
                $maxResult + 1
            );

            if (empty($relatedEntities)) {
                continue;
            }

            $relatedAdmin = $configurationPool->hasAdminByClass($relation->getRelatedClass())
                ? $configurationPool->getAdminByClass($relation->getRelatedClass())
                : null;

            $filterUrl = null;
            $hasMore = \count($relatedEntities) > $maxResult;
            if ($hasMore) {
                $relatedEntities = \array_slice($relatedEntities, 0, $maxResult);

                if ($relatedAdmin) {
                    $filterUrl = $this->getFilterParameters(
                        $relatedAdmin,
                        $relation,
                        $subject,
                    );
                }
            }

            $show
                ->with(
                    'prevent_delete_'.$relation->getRelatedClass(),
                    array_filter([
                        'class' => 'col-sm-6',
                        'label' => $relatedAdmin?->getClassnameLabel() ?? null,
                    ])
                )
                    ->add(
                        'prevent_delete_'.$relation->getRelatedClass().'_path'.$relation->getPath(),
                        null,
                        [
                            'virtual_field' => true,
                            'label' => $metadata['path_label'] ?? $relation->getPath(),
                            'template' => '@DrawSonataExtra/CRUD/show_prevent_delete.html.twig',
                            'relation' => $relation,
                            'related_admin' => $relatedAdmin,
                            'related_entities' => $relatedEntities,
                            'filter_url' => $filterUrl,
                            'has_more' => $hasMore,
                        ]
                    )
                ->end();
        }

        $show
            ->end();
    }

    private function getFilterParameters(
        AdminInterface $admin,
        PreventDelete $preventDelete,
        object $subject
    ): ?string {
        if (!$admin->getDatagrid()->hasFilter($preventDelete->getPath())) {
            return null;
        }

        $filter = $admin->getDatagrid()->getFilter($preventDelete->getPath());

        $value = $subject->getId();
        if ($filter->getFieldOption('multiple', false)) {
            $value = [$subject->getId()];
        }

        return $admin->generateUrl(
            'list',
            [
                'filter' => [
                    $filter->getFormName() => [
                        'value' => $value,
                    ],
                ],
            ]
        );
    }
}
