<?php
/**
 * @var array<string, mixed>|null                                   $status
 * @var bool                                                        $enabled
 * @var bool                                                        $locked
 * @var string                                                      $currency
 * @var string                                                      $currency_source
 * @var string                                                      $start_date
 * @var bool                                                        $needs_years
 * @var int                                                         $entities
 * @var bool                                                        $has_documents
 * @var array{before: array<string, int>, from: array<string, int>} $impact
 * @var list<string>                                                $payment_types
 * @var list<string>                                                $blockers
 * @var string|null                                                 $routing_url
 * @var list<string>                                                $steps
 * @var string                                                      $ack_impact
 * @var string                                                      $ack_documents
 * @var string                                                      $ack_payments
 */
?>

<?= view('partial/header') ?>

<?= view('accounting_settings/activation_panels', compact('status', 'enabled', 'locked', 'currency', 'currency_source', 'start_date', 'needs_years', 'entities', 'has_documents', 'impact', 'payment_types', 'blockers', 'routing_url', 'steps', 'ack_impact', 'ack_documents', 'ack_payments') + ['embedded' => false]) ?>

<?= view('partial/footer') ?>
