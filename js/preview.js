(function (Drupal, drupalSettings) {
  Drupal.behaviors.fileAdoptionPreview = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }
      const url = drupalSettings.file_adoption.preview_url;
      const wrapper = document.getElementById('file-adoption-preview');
      const details = document.getElementById('file-adoption-preview-wrapper');
      const scanButtons = document.querySelectorAll('input[name="quick_scan"], input[name="batch_scan"]');
      if (!url || !wrapper) {
        return;
      }

      const maxFailures = 3;
      let failureCount = 0;

      scanButtons.forEach((btn) => { btn.disabled = true; });

      function enableScanButtons() {
        scanButtons.forEach((btn) => { btn.disabled = false; });
      }

      function handleFailure(error) {
        failureCount += 1;
        if (console && error) {
          console.error('File Adoption preview error:', error);
        }
        if (failureCount >= maxFailures) {
          wrapper.textContent = Drupal.t('Unable to load preview. Please try again later.');
          clearInterval(intervalId);
          enableScanButtons();
        }
      }

      function loadPreview() {
        fetch(url)
          .then((response) => response.json())
          .then((data) => {
            if (data.markup) {
              wrapper.innerHTML = data.markup;
              if (details && typeof data.count !== 'undefined') {
                const summary = details.querySelector('summary');
                if (summary) {
                  summary.textContent = drupalSettings.file_adoption.preview_title + ' (' + data.count + ')';
                }
              }
              clearInterval(intervalId);
              enableScanButtons();
            }
            else {
              handleFailure('Invalid response');
            }
          })
          .catch((err) => handleFailure(err));
      }

      const intervalId = setInterval(loadPreview, 3000);
      loadPreview();
    }
  };
})(Drupal, drupalSettings);
