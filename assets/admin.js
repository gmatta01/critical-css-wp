/* Critical CSS for WP — Admin JS */
(function ($) {
	'use strict';

	var $table       = $('.ccss-pages-table');
	var $selectAll   = $('#ccss-select-all');
	var $rowChecks   = $('.ccss-row-checkbox');
	var $genSelected = $('#ccss-generate-selected');
	var $genAll      = $('#ccss-generate-all');
	var $bulkProg    = $('#ccss-bulk-progress');
	var $progressFill = $('.ccss-progress-fill');

	function updateBulkButton() {
		var checked = $rowChecks.filter(':checked').length;
		$genSelected.prop('disabled', checked === 0);
	}

	function updateProgressCard(stats) {
		if (!stats) return;
		var pct = stats.total > 0 ? Math.round((stats.with_css / stats.total) * 100) : 0;
		$progressFill.css('width', pct + '%');
		$('.ccss-stat-count').text(stats.with_css + ' / ' + stats.total);
		$('.ccss-stat-pct').text(pct + '% complete');
		$('.ccss-stat-detail').html(
			'Missing: ' + stats.without_css +
			(stats.with_errors > 0 ? ' | Errors: ' + stats.with_errors : '')
		);
	}

	function generateRow($row) {
		var postId = $row.data('post-id');
		var $btn   = $row.find('.ccss-generate-one');
		var $spin  = $row.find('.ccss-row-spinner');

		// Prevent double-clicks.
		if ($btn.data('generating')) return;
		$btn.data('generating', true).prop('disabled', true);
		$spin.show();

		$.post(CCSS_Admin.ajax_url, {
			action:      'ccss_generate_single',
			_ccss_nonce: CCSS_Admin.nonce,
			post_id:     postId
		}, function (resp) {
			$btn.data('generating', false).prop('disabled', false);
			$spin.hide();

			if (resp.success) {
				$row.find('.ccss-status-badge')
					.removeClass('ccss-status-none ccss-status-error')
					.addClass('ccss-status-ok')
					.text('✅ Yes');
				$row.find('.ccss-col-size').text(resp.data.size);
				$row.find('.ccss-col-generated').text(resp.data.generated);
				$row.find('.ccss-error-msg').text('');
				$btn.text(CCSS_Admin.i18n.generated);
				updateProgressCard(resp.data.stats);
			} else {
				$row.find('.ccss-status-badge')
					.removeClass('ccss-status-ok ccss-status-none')
					.addClass('ccss-status-error')
					.text('❌ Error');
				$row.find('.ccss-error-msg').text(resp.data.message || CCSS_Admin.i18n.failed);
				$btn.text(CCSS_Admin.i18n.failed);
			}
		}).fail(function () {
			$btn.data('generating', false).prop('disabled', false);
			$spin.hide();
			$btn.text(CCSS_Admin.i18n.failed);
		});
	}

	function generateBulk(postIds) {
		$genSelected.prop('disabled', true);
		$genAll.prop('disabled', true);
		$bulkProg.show().text(CCSS_Admin.i18n.generating);

		$.post(CCSS_Admin.ajax_url, {
			action:      'ccss_bulk_generate',
			_ccss_nonce: CCSS_Admin.nonce,
			post_ids:    postIds
		}, function (resp) {
			$genSelected.prop('disabled', false);
			$genAll.prop('disabled', false);
			$bulkProg.text(resp.success ? resp.data.message : (resp.data.message || CCSS_Admin.i18n.failed));

			if (resp.success && resp.data.stats) {
				updateProgressCard(resp.data.stats);
			}
			// Reload page to refresh the table fully after bulk.
			setTimeout(function () { window.location.reload(); }, 1500);
		}).fail(function () {
			$genSelected.prop('disabled', false);
			$genAll.prop('disabled', false);
			$bulkProg.text(CCSS_Admin.i18n.failed);
		});
	}

	// ── Events ──

	$selectAll.on('change', function () {
		$rowChecks.prop('checked', this.checked);
		updateBulkButton();
	});

	$rowChecks.on('change', updateBulkButton);

	$table.on('click', '.ccss-generate-one', function () {
		var $row = $(this).closest('tr');
		var $btn = $(this);
		$btn.text(CCSS_Admin.i18n.generating);
		generateRow($row);
	});

	$genSelected.on('click', function () {
		var ids = $rowChecks.filter(':checked').map(function () { return this.value; }).get();
		if (ids.length === 0) {
			alert(CCSS_Admin.i18n.no_pages);
			return;
		}
		if (!confirm(CCSS_Admin.i18n.confirm_selected)) return;
		generateBulk(ids);
	});

	$genAll.on('click', function () {
		if (!confirm(CCSS_Admin.i18n.confirm_all)) return;
		generateBulk([]);
	});

})(jQuery);
