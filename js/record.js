(function ($) {
    Drupal.behaviors.entityMetrics = {
      attach: function (context, settings) {
        if (!once('entity_metrics_record', 'html').length) {
          return;
        }

        $.ajax({
          url: '/entity-metrics/visit',
          method: 'POST',
          data: {
            currentPath: drupalSettings.path.currentPath
          }
        });
      }
    };
})(jQuery);
