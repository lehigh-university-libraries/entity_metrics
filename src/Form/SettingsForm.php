<?php

declare(strict_types=1);

namespace Drupal\entity_metrics\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Entity Metrics settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'entity_metrics_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['entity_metrics.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['cookie'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cookie name'),
      '#description' => $this->t('<p>Name of cookie that when set and equal to "1" will set a flag to allow filtering out those metrics.</p>
        <p>This allows not tracking things like staff page views.</p>
        <p>The value will need set by some other service, this module does not handle setting the cookie. e.g. <a href="https://github.com/lehigh-university-libraries/cookie-toggler">cookie-toggler</a>.</p>'),
      '#default_value' => $this->config('entity_metrics.settings')->get('cookie'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('entity_metrics.settings')
      ->set('cookie', $form_state->getValue('cookie'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
