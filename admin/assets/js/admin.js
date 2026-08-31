/**
 * Media Optimizer by Webxperthub — Bulk Optimize admin UI.
 *
 * Wires the "Start Bulk Optimize" button to the queue AJAX endpoints
 * and drives the progress bar from real server-reported numbers only.
 */
(function ($) {
  "use strict";

  $(function () {
    var $startButton = $("#mopw-start-bulk");
    var $cancelButton = $("#mopw-cancel-bulk");
    var $progressWrap = $("#mopw-progress-wrap");
    var $progressBar = $("#mopw-progress-bar");
    var $progressText = $("#mopw-progress-text");

    if (!$startButton.length) {
      return;
    }

    var i18n = (mopwAdmin && mopwAdmin.i18n) || {};
    var pollTimer = null;
    var isRequestInFlight = false;
    var totalQueuedAtStart = 0;

    function t(key, fallback) {
      return i18n[key] || fallback;
    }

    function sprintf() {
      var args = Array.prototype.slice.call(arguments);
      var str = args.shift();
      return str.replace(/%(\d+)\$d|%d/g, function (match, num) {
        if (num) {
          return args[parseInt(num, 10) - 1];
        }
        return args.shift();
      });
    }

    function showMessage(message) {
      $progressWrap.show();
      $progressBar.hide();
      $progressText.show().text(message);
    }

    function showProgress() {
      $progressWrap.show();
      $progressBar.show();
      $cancelButton.show();
    }

    function resetToIdle() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
      $startButton
        .prop("disabled", false)
        .text(t("startLabel", "Start Bulk Optimize"));
      $cancelButton
        .hide()
        .prop("disabled", false)
        .text(t("cancelLabel", "Cancel"));
    }

    $startButton.on("click", function () {
      if (parseInt(mopwAdmin.unoptimizedCount, 10) === 0) {
        showMessage(
          t(
            "noImages",
            "No images to optimize — your media library is already up to date.",
          ),
        );
        return;
      }

      $startButton
        .prop("disabled", true)
        .text(mopwAdmin.startingLabel || t("starting", "Starting…"));

      $.post(mopwAdmin.ajaxUrl, {
        action: "mopw_start_bulk",
        nonce: mopwAdmin.nonce,
      })
        .done(function (response) {
          if (!response.success) {
            resetToIdle();
            showMessage(
              (response.data && response.data.message) ||
                t("somethingWrong", "Something went wrong."),
            );
            return;
          }

          totalQueuedAtStart = response.data.queued;

          if (totalQueuedAtStart === 0) {
            resetToIdle();
            showMessage(
              t(
                "noImages",
                "No images to optimize — your media library is already up to date.",
              ),
            );
            return;
          }

          showProgress();
          $progressBar.attr("max", totalQueuedAtStart).attr("value", 0);
          $progressText.text(
            sprintf(
              t("queued", "Queued %d images. Processing…"),
              totalQueuedAtStart,
            ),
          );

          pollTimer = setInterval(runNextBatch, 2000);
          runNextBatch();
        })
        .fail(function () {
          resetToIdle();
          showMessage(
            t(
              "couldNotReach",
              "Could not reach the server. Please try again.",
            ),
          );
        });
    });

    $cancelButton.on("click", function () {
      if (
        !confirm(
          t(
            "cancelConfirm",
            "Stop the current optimization run? Images already processed will keep their optimized version — only remaining images will be skipped.",
          ),
        )
      ) {
        return;
      }

      $cancelButton
        .prop("disabled", true)
        .text(t("cancelling", "Cancelling…"));

      $.post(mopwAdmin.ajaxUrl, {
        action: "mopw_cancel_bulk",
        nonce: mopwAdmin.nonce,
      })
        .done(function () {
          resetToIdle();
          showMessage(
            t(
              "cancelled",
              "Cancelled. Already-optimized images were kept; the rest were skipped.",
            ),
          );
        })
        .fail(function () {
          $cancelButton
            .prop("disabled", false)
            .text(t("cancelLabel", "Cancel"));
          alert(t("cancelFailed", "Could not cancel — please try again."));
        });
    });

    function runNextBatch() {
      if (isRequestInFlight) {
        return;
      }

      isRequestInFlight = true;

      $.post(mopwAdmin.ajaxUrl, {
        action: "mopw_run_batch",
        nonce: mopwAdmin.nonce,
      })
        .done(function (response) {
          if (!response.success) {
            resetToIdle();
            showMessage(
              (response.data && response.data.message) ||
                t("somethingWrong", "Something went wrong."),
            );
            return;
          }

          var stats = response.data;
          var completed = totalQueuedAtStart - stats.remaining;

          $progressBar.attr("value", Math.max(0, completed));
          $progressText.text(
            sprintf(
              t(
                "processed",
                "Processed %1$d of %2$d (%3$d failed this batch)",
              ),
              completed,
              totalQueuedAtStart,
              stats.failed,
            ),
          );

          if (stats.remaining <= 0) {
            resetToIdle();
            $progressText.text(
              sprintf(
                t("done", "Done! %d images processed."),
                totalQueuedAtStart,
              ),
            );
          }
        })
        .fail(function () {
          $progressText.text(
            t(
              "retrying",
              "Lost connection during processing. Retrying…",
            ),
          );
        })
        .always(function () {
          isRequestInFlight = false;
        });
    }
  });
})(jQuery);
