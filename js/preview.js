(function (Drupal, drupalSettings) {
  Drupal.behaviors.fileAdoptionPreview = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }
      const url = drupalSettings.file_adoption.preview_url;
      const wrapper = document.getElementById('file-adoption-preview');
      const details = document.getElementById('file-adoption-preview-wrapper');
      const scanButton = document.querySelector('input[name="scan"]');
      if (!url || !wrapper) {
        return;
      }

      if (scanButton) {
        scanButton.disabled = true;
      }

      const maxFailures = 3;
      let failureCount = 0;

      function enableScanButton() {
        if (scanButton) {
          scanButton.disabled = false;
        }
      }

      function handleFailure(error) {
        failureCount += 1;
        if (console && error) {
          console.error('File Adoption preview error:', error);
        }
        if (failureCount >= maxFailures) {
          wrapper.textContent = Drupal.t('Unable to load preview. Please try again later.');
          clearInterval(intervalId);
          enableScanButton();
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
              enableScanButton();
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
