(function ($) {
  "use strict";

  $(function () {
    var $startButton = $("#mopw-start-bulk");
    var $cancelButton = $("#mopw-cancel-bulk");
    var $progressWrap = $("#mopw-progress-wrap");
    var $progressBar = $("#mopw-progress-bar");
    var $progressText = $("#mopw-progress-text");

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

    // showMessage/showProgress/resetToIdle/runNextBatch are declared at
    // this top level (not nested inside the "if ($startButton.length)"
    // guard below) because the resume-on-load check further down calls
    // them on ANY admin page this script loads on — including the Media
    // Library (upload.php), where #mopw-start-bulk does not exist. When
    // they were nested inside that guard, calling them from upload.php
    // threw a ReferenceError that aborted the rest of this file,
    // silently breaking the "Restore Original" click handler registered
    // near the bottom.
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
            t("retrying", "Lost connection during processing. Retrying…"),
          );
        })
        .always(function () {
          isRequestInFlight = false;
        });
    }

    // Everything else Bulk-Optimize-specific (the click handlers) stays
    // scoped inside this guard — it only makes sense to bind these on
    // the Bulk Optimize page, where the buttons actually exist.
    if ($startButton.length) {
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
    }

    if (mopwAdmin.runActive) {
      totalQueuedAtStart = parseInt(mopwAdmin.runTotal, 10) || 0;
      showProgress();
      pollTimer = setInterval(runNextBatch, 2000);
      runNextBatch();
    }

    // --- Restore Original (Media Library list view) ---
    // Deliberately OUTSIDE the $startButton guard above, since this
    // needs to work on the Media Library page (upload.php), where
    // #mopw-start-bulk does not exist at all.
    $(document).on("click", ".mopw-restore-link", function (e) {
      e.preventDefault();

      var $link = $(this);
      var attachmentId = $link.data("attachment-id");
      var nonce = $link.data("nonce");

      if (
        !confirm(
          "Restore the original image? This will replace the current optimized version.",
        )
      ) {
        return;
      }

      var originalText = $link.text();
      $link.text("Restoring…");

      $.post(ajaxurl, {
        action: "mopw_restore_original",
        attachment_id: attachmentId,
        nonce: nonce,
      })
        .done(function (response) {
          if (response.success) {
            $link.closest("tr").fadeOut(200, function () {
              location.reload();
            });
          } else {
            alert(
              "Could not restore: " +
                ((response.data && response.data.message) || "Unknown error."),
            );
            $link.text(originalText);
          }
        })
        .fail(function () {
          alert("Could not reach the server. Please try again.");
          $link.text(originalText);
        });
    });

    // --- Re-optimize (Media Library list view) ---
    // Same reasoning as Restore Original above: deliberately outside
    // the $startButton guard so it works on the Media Library page.
    $(document).on("click", ".mopw-reoptimize-link", function (e) {
      e.preventDefault();

      var $link = $(this);
      var attachmentId = $link.data("attachment-id");
      var nonce = $link.data("nonce");

      if (
        !confirm(
          "Re-optimize this image using current settings? The image will be restored to its original first, then re-optimized fresh.",
        )
      ) {
        return;
      }

      var originalText = $link.text();
      $link.text("Re-optimizing…");

      $.post(ajaxurl, {
        action: "mopw_reoptimize",
        attachment_id: attachmentId,
        nonce: nonce,
      })
        .done(function (response) {
          if (response.success) {
            $link.closest("tr").fadeOut(200, function () {
              location.reload();
            });
          } else {
            alert(
              "Could not re-optimize: " +
                ((response.data && response.data.message) || "Unknown error."),
            );
            $link.text(originalText);
          }
        })
        .fail(function () {
          alert("Could not reach the server. Please try again.");
          $link.text(originalText);
        });
    });
  });
})(jQuery);
