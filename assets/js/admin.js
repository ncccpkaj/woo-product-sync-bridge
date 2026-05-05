(function ($) {
	'use strict';

	var state = {
		selectedProducts: [],
		updateProductId: 0,
		updateTargetId: 0,
		conflicts: [],
		unsupported: [],
		searchMode: '',
		searchPage: 1,
		searchHasMore: false
	};

	function connectionsOptions() {
		return (wpsbAdmin.connections || []).map(function (site) {
			return '<option value="' + esc(site.id) + '">' + esc(site.name) + ' - ' + esc(site.url) + '</option>';
		}).join('');
	}

	function esc(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function ajax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = wpsbAdmin.nonce;
		return $.ajax({
			url: wpsbAdmin.ajaxUrl,
			method: 'POST',
			data: data,
			traditional: true
		});
	}

	function log(lines) {
		if (!Array.isArray(lines)) {
			lines = [lines];
		}
		var $log = $('#wpsb-live-log');
		lines.forEach(function (line) {
			$log.append(esc(line) + "\n");
		});
		$log.scrollTop($log[0].scrollHeight);
	}

	function progress(done, total, mode) {
		var percent = total ? Math.round((done / total) * 100) : 0;
		var $bar = $('#wpsb-progress-bar');
		$bar.removeClass('is-transfer is-replace is-update');
		if (mode) {
			$bar.addClass('is-' + mode);
		}
		$bar.css('width', percent + '%');
		$('#wpsb-progress-text').text(percent + '%');
	}

	function openModal(view) {
		$('#wpsb-live-log').text('');
		progress(0, 1, 'transfer');
		state.conflicts = [];
		state.unsupported = [];
		renderConflicts();
		renderUnsupported();
		$('#wpsb-transfer-view').prop('hidden', view !== 'transfer');
		$('#wpsb-update-view').prop('hidden', view !== 'update');
		$('#wpsb-modal').attr('aria-hidden', 'false').addClass('is-open');
	}

	function closeModal() {
		$('#wpsb-modal').attr('aria-hidden', 'true').removeClass('is-open');
	}

	function selectedProductIds() {
		return $('tbody th.check-column input[type="checkbox"]:checked').map(function () {
			return parseInt($(this).val(), 10);
		}).get().filter(Boolean);
	}

	function updateTransferButton() {
		var ids = selectedProductIds();
		state.selectedProducts = ids;
		var $button = $('#wpsb-transfer-products');
		if (!$button.length) {
			return;
		}
		$button.prop('disabled', !ids.length);
		$button.text(ids.length ? wpsbAdmin.i18n.transferCount.replace('%d', ids.length) : wpsbAdmin.i18n.transfer);
	}

	function addTransferButton() {
		if (!wpsbAdmin.hasConnection || !$('.page-title-action').length || $('#wpsb-transfer-products').length) {
			return;
		}
		var $button = $('<button type="button" id="wpsb-transfer-products" class="page-title-action wpsb-title-button" disabled>' + esc(wpsbAdmin.i18n.transfer) + '</button>');
		$('.page-title-action').last().after($button);
		updateTransferButton();
	}

	function renderConflicts() {
		var $box = $('#wpsb-conflicts');
		var $list = $('#wpsb-conflict-list');
		$list.empty();
		if (!state.conflicts.length) {
			$box.prop('hidden', true);
			$('#wpsb-replace-selected').prop('disabled', true);
			return;
		}
		state.conflicts.forEach(function (item, index) {
			var target = item.target || {};
			$list.append(
				'<label class="wpsb-conflict-item">' +
				'<input type="checkbox" data-index="' + index + '"> ' +
				'<span>' + esc(item.title) + ' <code>' + esc(item.sku) + '</code></span>' +
				'<small>Target: #' + esc(target.id) + ' ' + esc(target.title) + '</small>' +
				'</label>'
			);
		});
		$box.prop('hidden', false);
	}

	function renderUnsupported() {
		var $box = $('#wpsb-unsupported');
		var $list = $('#wpsb-unsupported-list');
		$list.empty();
		if (!state.unsupported.length) {
			$box.prop('hidden', true);
			return;
		}
		state.unsupported.forEach(function (item) {
			$list.append(
				'<div class="wpsb-unsupported-item">' +
				'<strong>' + esc(item.title || ('Product #' + item.id)) + '</strong> ' +
				'<code>' + esc(item.type || 'unknown') + '</code>' +
				'<small>' + esc(item.why || 'Unsupported product type.') + '</small>' +
				'</div>'
			);
		});
		$box.prop('hidden', false);
	}

	function runInstantTransfer(connectionId, ids) {
		var batches = [];
		for (var i = 0; i < ids.length; i++) {
			batches.push([ids[i]]);
		}
		var completed = 0;

		function next() {
			if (!batches.length) {
				progress(ids.length, ids.length, 'transfer');
				log(wpsbAdmin.i18n.complete);
				renderConflicts();
				return;
			}
			var batch = batches.shift();
			log('Starting batch: ' + batch.join(', '));
			ajax('wpsb_transfer_batch', {
				connection_id: connectionId,
				product_ids: batch,
				method: 'instant'
			}).done(function (res) {
				if (res.success) {
					log(res.data.logs || []);
					if (res.data.conflicts && res.data.conflicts.length) {
						state.conflicts = state.conflicts.concat(res.data.conflicts);
					}
					if (res.data.unsupported && res.data.unsupported.length) {
						state.unsupported = state.unsupported.concat(res.data.unsupported);
						renderUnsupported();
					}
				} else {
					log(res.data && res.data.message ? res.data.message : 'Transfer batch failed.');
				}
			}).fail(function () {
				log('Transfer batch failed with a server error.');
			}).always(function () {
				completed += batch.length;
				progress(Math.min(completed, ids.length), ids.length, 'transfer');
				next();
			});
		}

		next();
	}

	function initProductList() {
		if (!$('#the-list').length) {
			return;
		}
		$('#wpsb-transfer-site, #wpsb-update-site').html(connectionsOptions());
		addTransferButton();
		$(document).on('change', 'tbody th.check-column input[type="checkbox"], thead .check-column input, tfoot .check-column input', function () {
			setTimeout(updateTransferButton, 20);
		});
		$(document).on('click', '#wpsb-transfer-products', function () {
			state.selectedProducts = selectedProductIds();
			if (!state.selectedProducts.length) {
				return;
			}
			openModal('transfer');
		});
	}

	function initModal() {
		$(document).on('click', '.wpsb-modal__close', closeModal);
		$(document).on('click', '#wpsb-modal', function (event) {
			if (event.target === this) {
				closeModal();
			}
		});

		$(document).on('click', '#wpsb-start-transfer', function () {
			var connectionId = $('#wpsb-transfer-site').val();
			var method = $('input[name="wpsb_transfer_method"]:checked').val();
			if (!connectionId) {
				log(wpsbAdmin.i18n.chooseSite);
				return;
			}
			if (method === 'scheduled') {
				ajax('wpsb_transfer_batch', {
					connection_id: connectionId,
					product_ids: state.selectedProducts,
					method: 'scheduled'
				}).done(function (res) {
					log(res.success ? res.data.logs : (res.data.message || 'Schedule failed.'));
					progress(1, 1, 'transfer');
				});
			} else {
				runInstantTransfer(connectionId, state.selectedProducts);
			}
		});

		$(document).on('change', '#wpsb-conflict-list input[type="checkbox"]', function () {
			$('#wpsb-replace-selected').prop('disabled', !$('#wpsb-conflict-list input:checked').length);
		});

		$(document).on('click', '#wpsb-replace-selected', function () {
			var items = $('#wpsb-conflict-list input:checked').map(function () {
				var item = state.conflicts[parseInt($(this).data('index'), 10)];
				return {
					source_id: item.source_id,
					target_id: item.target.id
				};
			}).get();
			if (!items.length) {
				return;
			}
			runReplaceProducts($('#wpsb-transfer-site').val(), items);
		});
	}

	function runReplaceProducts(connectionId, items) {
		var done = 0;
		var total = items.length;
		$('#wpsb-replace-selected').prop('disabled', true);
		progress(0, total, 'replace');
		log('Starting replace for ' + total + ' product(s).');

		function next() {
			if (!items.length) {
				progress(total, total, 'replace');
				log('Replace completed.');
				return;
			}

			var item = items.shift();
			log('Replacing source #' + item.source_id + ' into target #' + item.target_id + '.');
			ajax('wpsb_replace_products', {
				connection_id: connectionId,
				items_json: JSON.stringify([item])
			}).done(function (res) {
				if (res.success) {
					log(res.data.logs || []);
					if (res.data.completed && res.data.completed.length) {
						removeConflict(item.source_id, item.target_id);
					}
				} else {
					log(res.data && res.data.message ? res.data.message : 'Replace failed.');
				}
			}).fail(function () {
				log('Replace failed with a server error.');
			}).always(function () {
				done++;
				progress(done, total, 'replace');
				renderConflicts();
				next();
			});
		}

		next();
	}

	function removeConflict(sourceId, targetId) {
		state.conflicts = state.conflicts.filter(function (conflict) {
			var target = conflict.target || {};
			return parseInt(conflict.source_id, 10) !== parseInt(sourceId, 10) || parseInt(target.id, 10) !== parseInt(targetId, 10);
		});
	}

	function initUpdateFlow() {
		$(document).on('click', '.wpsb-update-row', function (event) {
			event.preventDefault();
			state.updateProductId = parseInt($(this).data('product-id'), 10);
			state.updateTargetId = 0;
			$('#wpsb-update-title').text($(this).data('title') || '');
			$('#wpsb-update-sku').text($(this).data('sku') || '');
			$('#wpsb-search-results').empty();
			openModal('update');
		});

		function renderSearchResults(res, append) {
			var results = res.success && res.data.results ? res.data.results : [];
			var $box = $('#wpsb-search-results');
			if (!append) {
				$box.empty();
			} else {
				$box.find('.wpsb-load-more').remove();
			}
			if (!results.length && !append) {
				$box.html('<p>No products found.</p>');
				return;
			}
			results.forEach(function (product) {
				$box.append(
					'<button type="button" class="wpsb-result" data-id="' + esc(product.id) + '">' +
					(product.image ? '<img src="' + esc(product.image) + '" alt="">' : '') +
					'<span><strong>' + esc(product.title) + '</strong><small>#' + esc(product.id) + ' | ' + esc(product.type) + ' | SKU: ' + esc(product.sku || '-') + '</small></span>' +
					'</button>'
				);
			});
			state.searchHasMore = !!(res.success && res.data.has_more);
			state.searchPage = res.success && res.data.page ? parseInt(res.data.page, 10) : state.searchPage;
			if (state.searchHasMore) {
				$box.append('<button type="button" class="button wpsb-load-more">Load more results</button>');
			}
		}

		function searchPaged(mode, page, append) {
			state.searchMode = mode;
			state.searchPage = page || 1;
			if (!append) {
				state.updateTargetId = 0;
				$('#wpsb-search-results').html('<p>Searching...</p>');
			}
			ajax('wpsb_search_products', {
				connection_id: $('#wpsb-update-site').val(),
				product_id: state.updateProductId,
				mode: mode,
				page: state.searchPage,
				per_page: 20
			}).done(function (res) {
				renderSearchResults(res, append);
			}).fail(function () {
				$('#wpsb-search-results').html('<p>Search failed.</p>');
			});
		}

		$(document).on('click', '#wpsb-search-sku', function () { searchPaged('sku', 1, false); });
		$(document).on('click', '#wpsb-search-title', function () { searchPaged('title', 1, false); });
		$(document).on('click', '.wpsb-load-more', function () {
			searchPaged(state.searchMode || 'title', state.searchPage + 1, true);
		});
		$(document).on('click', '.wpsb-result', function () {
			$('.wpsb-result').removeClass('is-selected');
			$(this).addClass('is-selected');
			state.updateTargetId = parseInt($(this).data('id'), 10);
		});

		function update(method) {
			if (!state.updateTargetId) {
				log('Choose a target product first.');
				return;
			}
			ajax('wpsb_update_product', {
				connection_id: $('#wpsb-update-site').val(),
				product_id: state.updateProductId,
				target_product_id: state.updateTargetId,
				part: $('#wpsb-update-part').val(),
				method: method
			}).done(function (res) {
				log(res.success ? res.data.logs : (res.data.message || 'Update failed.'));
				progress(1, 1, 'update');
			});
		}

		$(document).on('click', '#wpsb-update-instant', function () { update('instant'); });
		$(document).on('click', '#wpsb-update-schedule', function () { update('scheduled'); });
	}

	function initSettings() {
		var $mount = $('#wpsb-settings-connections');
		if (!$mount.length) {
			return;
		}

		var $json = $('#wpsb_connections_json');
		var rows = [];
		try {
			rows = JSON.parse($json.val() || '[]') || [];
		} catch (e) {
			rows = [];
		}

		function sync() {
			$json.val(JSON.stringify(rows));
		}

		function render() {
			$mount.empty();
			if (!rows.length) {
				$mount.append('<p>No connected websites yet.</p>');
			}
			rows.forEach(function (row, index) {
				$mount.append(
					'<div class="wpsb-connection" data-index="' + index + '">' +
					'<label>Site name<input type="text" data-field="name" value="' + esc(row.name || '') + '"></label>' +
					'<label>Site URL<input type="url" data-field="url" value="' + esc(row.url || '') + '" placeholder="https://example.com"></label>' +
					'<label>Shared secret<input type="password" data-field="secret" value="' + esc(row.secret || '') + '"></label>' +
					'<label class="wpsb-inline"><input type="checkbox" data-field="enabled" ' + (row.enabled ? 'checked' : '') + '> Enabled</label>' +
					'<div class="wpsb-connection__actions">' +
					'<button type="button" class="button wpsb-test-connection">Test</button> ' +
					'<button type="button" class="button-link-delete wpsb-remove-connection">Remove</button>' +
					'<span class="wpsb-connection-status">' + esc(row.last_status || '') + '</span>' +
					'</div>' +
					'</div>'
				);
			});
			sync();
		}

		$(document).on('input change', '.wpsb-connection [data-field]', function () {
			var $field = $(this);
			var index = parseInt($field.closest('.wpsb-connection').data('index'), 10);
			var key = $field.data('field');
			rows[index][key] = $field.attr('type') === 'checkbox' ? ($field.is(':checked') ? 1 : 0) : $field.val();
			sync();
		});

		$(document).on('click', '#wpsb-add-connection', function () {
			rows.push({ id: 'conn_' + Date.now(), name: '', url: '', secret: '', enabled: 1 });
			render();
		});

		$(document).on('click', '.wpsb-remove-connection', function () {
			rows.splice(parseInt($(this).closest('.wpsb-connection').data('index'), 10), 1);
			render();
		});

		$(document).on('click', '.wpsb-test-connection', function () {
			var $row = $(this).closest('.wpsb-connection');
			var index = parseInt($row.data('index'), 10);
			var id = rows[index].id;
			var $status = $row.find('.wpsb-connection-status').text('Testing connection...');
			sync();
			ajax('wpsb_test_connection', { connection_id: id, connection: JSON.stringify(rows[index]) }).done(function (res) {
				$status.text(res.success ? 'Connected' : (res.data.message || 'Failed'));
			}).fail(function () {
				$status.text('Failed');
			});
		});

		$(document).on('click', '#wpsb-regenerate-secret', function () {
			if (!window.confirm('Regenerate this site shared secret? Existing remote connections using the old secret must be updated.')) {
				return;
			}
			ajax('wpsb_regenerate_secret').done(function (res) {
				if (res.success && res.data.secret) {
					$('#wpsb-local-secret').text(res.data.secret);
				}
			});
		});

		$(document).on('click', '#wpsb-clear-log', function () {
			ajax('wpsb_clear_log').done(function () {
				$('.wpsb-log-viewer').val('');
			});
		});

		render();
	}

	$(function () {
		initProductList();
		initModal();
		initUpdateFlow();
		initSettings();
	});
})(jQuery);
