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
    $config = $this->config('entity_metrics.settings');
    $form['geolocation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enrich usage with local geolocation on cron'),
      '#default_value' => $config->get('geolocation_enabled') ?? FALSE,
      '#description' => $this->t('Configure GeoIP Auto-Update to download GeoLite2-City first. Lookups use the local file and never send visitor IPs to an API.'),
    ];
    $form['geolocation_database'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Local City MMDB path'),
      '#default_value' => $config->get('geolocation_database') ?? 'private://GeoLite2-City.mmdb',
      '#required' => TRUE,
      '#description' => $this->t('Use private://GeoLite2-City.mmdb for GeoIP Auto-Update, or an absolute local path. Remote URLs are not supported.'),
    ];
    $form['geolocation_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Events per cron batch'),
      '#default_value' => $config->get('geolocation_batch_size') ?? 500,
      '#min' => 1,
      '#max' => 10000,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $path = trim($form_state->getValue('geolocation_database'));
    if ((!str_starts_with($path, 'private://') && !str_starts_with($path, '/')) || !str_ends_with($path, '.mmdb')) {
      $form_state->setErrorByName('geolocation_database', $this->t('Enter a private:// or absolute local .mmdb path.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('entity_metrics.settings')
      ->set('cookie', $form_state->getValue('cookie'))
      ->set('geolocation_enabled', (bool) $form_state->getValue('geolocation_enabled'))
      ->set('geolocation_database', trim($form_state->getValue('geolocation_database')))
      ->set('geolocation_batch_size', (int) $form_state->getValue('geolocation_batch_size'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
