<?php

namespace Draw\Bundle\SonataExtraBundle\Block;

use Draw\Bundle\SonataExtraBundle\ExpressionLanguage\ExpressionLanguage;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\BlockBundle\Block\BlockContextInterface;
use Sonata\BlockBundle\Block\Service\BlockServiceInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Twig\Environment;

class AdminMonitoringBlockService implements BlockServiceInterface
{
    use BlockFilterTrait;
    use BlockTrait;

    public function __construct(
        private Pool $pool,
        private Environment $twig,
        private ExpressionLanguage $expressionLanguage
    ) {
    }

    public function configureSettings(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(
                [
                    'icon' => 'fas fa-line-chart',
                    'text' => 'Monitoring',
                    'translation_domain' => null,
                    'css_class' => 'bg-aqua',
                    'code' => false,
                    'filters' => [],
                    'limit' => 256,
                    'template' => '@DrawSonataExtra/Block/block_monitoring.html.twig',
                    'thresholds' => [
                        'success' => [
                            'if' => 'count == 0',
                            'settings' => [
                                'css_class' => 'bg-green',
                            ],
                        ],
                        'alert' => [
                            'if' => 'count >= 1',
                            'settings' => [
                                'css_class' => 'bg-red',
                            ],
                        ],
                    ],
                ]
            );
    }

    public function execute(BlockContextInterface $blockContext, ?Response $response = null): Response
    {
        $admin = $this->pool->getAdminByAdminCode($blockContext->getSetting('code'));

        $dataGrid = $this->buildFilter($admin, $blockContext);
        $count = $dataGrid->getPager()->countResults();

        $settings = array_merge(
            $blockContext->getSettings(),
            $this->findThresholdSetting($blockContext->getSetting('thresholds'), $count)['settings'] ?? []
        );

        $response = $response ?: new Response();

        return
            $response->setContent(
                $this->twig->render(
                    $blockContext->getTemplate(),
                    [
                        'block' => $blockContext->getBlock(),
                        'settings' => $settings,
                        'count' => $count,
                        'link_label' => 'stats_view_more',
                        'link' => $admin->generateUrl('list', ['filter' => $blockContext->getSetting('filters')]),
                        'translation_domain' => $blockContext->getSetting('translation_domain') ?? $admin->getTranslationDomain(),
                    ]
                )
            )
                ->setPrivate()
                ->setTtl(0);
    }

    private function findThresholdSetting(array $thresholds, int $count): array
    {
        foreach ($thresholds as $threshold) {
            if (!isset($threshold['if'])) {
                return $threshold;
            }

            if ($this->expressionLanguage->evaluate($threshold['if'], ['count' => $count])) {
                return $threshold;
            }
        }

        return [];
    }
}
