/* global phpbb */
(function($) {
	'use strict';

	var $app = $('#topic-tag-app');
	if (!$app.length) {
		return;
	}

	$app.addClass('is-js');

	var $list = $('#topic-tag-list'),
		$form = $('#acp_topic_tag'),
		$name = $('#tag_name'),
		$color = $('#tag_color'),
		$colorText = $('#tag_color_text'),
		$enabled = $('#tag_enabled'),
		$preview = $('#topic-tag-preview'),
		$formError = $('#topic-tag-form-error'),
		$notices = $('#topic-tag-notices'),
		$noticeTemplate = $('#topic-tag-notice-template'),
		$colorError = $('#tag_color_error'),
		$dirtyLabel = $('#topic-tag-dirty'),
		$tagSearch = $('#topic-tag-search'),
		$statusFilter = $('#topic-tag-status-filter'),
		$forumSearch = $('#topic-tag-forum-search'),
		$forumPicker = $('#topic-tag-forums'),
		$selectedCount = $('#topic-tag-forum-selected'),
		$resultCount = $('#topic-tag-result-count'),
		$noResults = $('#topic-tag-no-results'),
		$orderHint = $('#topic-tag-order-hint'),
		$selectedRow = $(),
		dirty = false,
		draggedRow = null,
		dragImage = null,
		dropCommitted = false,
		previousOrder = [],
		orderSaving = false;

	function format(template, value) {
		return String(template).replace('%d', value).replace('%s', value);
	}

	function removeNotice($notice, immediate) {
		window.clearTimeout($notice.data('notice-timer'));
		if (immediate) {
			$notice.remove();
			return;
		}
		$notice.removeClass('is-visible');
		window.setTimeout(function() {
			$notice.remove();
		}, 180);
	}

	function showNotice(message, isError) {
		var $notice = $noticeTemplate.clone().removeAttr('id hidden').toggleClass('is-error', !!isError)
			.attr({role: isError ? 'alert' : 'status', 'aria-live': isError ? 'assertive' : 'polite'});
		$notice.find('#topic-tag-notice-message').removeAttr('id').text(message);
		$notices.prepend($notice);
		$notice[0].getBoundingClientRect();
		$notice.addClass('is-visible');
		$notices.children('.topic-tag-notice').slice(4).each(function() {
			removeNotice($(this), true);
		});
		if (!isError) {
			$notice.data('notice-timer', window.setTimeout(function() {
				removeNotice($notice, false);
			}, 4000));
		}
	}

	$notices.on('click', '.topic-tag-notice-close', function() {
		removeNotice($(this).closest('.topic-tag-notice'), false);
	});

	function responseData(xhr) {
		if (xhr.responseJSON) {
			return xhr.responseJSON;
		}
		try {
			return JSON.parse(xhr.responseText);
		} catch (error) {
			return {};
		}
	}

	function requestError(xhr) {
		var response = responseData(xhr),
			message = response.message || $app.data('request-failed');
		showNotice(message, true);
		return response;
	}

	function contrastColor(hex) {
		var clean = hex.replace('#', ''), channels = [], luminance, whiteContrast, blackContrast, i, channel;
		if (!/^[0-9a-f]{6}$/i.test(clean)) {
			return '#000000';
		}
		for (i = 0; i < 3; i++) {
			channel = parseInt(clean.substr(i * 2, 2), 16) / 255;
			channels.push(channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4));
		}
		luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
		whiteContrast = 1.05 / (luminance + 0.05);
		blackContrast = (luminance + 0.05) / 0.05;
		return whiteContrast >= blackContrast ? '#FFFFFF' : '#000000';
	}

	function normalizeColor(value) {
		value = String(value || '').trim().toUpperCase();
		if (value && value.charAt(0) !== '#') {
			value = '#' + value;
		}
		return value;
	}

	function validateColor(showError) {
		var valid = /^#[0-9A-F]{6}$/.test(normalizeColor($colorText.val()));
		$colorText.attr('aria-invalid', valid ? 'false' : 'true');
		$colorError.prop('hidden', valid || !showError);
		return valid;
	}

	function updatePreview() {
		var value = normalizeColor($colorText.val()), valid = /^#[0-9A-F]{6}$/.test(value);
		$preview.text($name.val().trim() || $app.data('preview'));
		if (valid) {
			$preview.css({backgroundColor: value, color: contrastColor(value)});
		}
	}

	function setDirty(value) {
		dirty = !!value;
		$dirtyLabel.prop('hidden', !dirty);
	}

	function confirmDiscard(callback) {
		if (dirty && !window.confirm($app.data('discard'))) {
			return false;
		}
		callback();
		return true;
	}

	function formTokenData() {
		return {
			creation_time: $form.find('input[name="creation_time"]').val(),
			form_token: $form.find('input[name="form_token"]').val()
		};
	}

	function forumIdsForRow($row) {
		var value = String($row.attr('data-forum-ids') || '');
		return value ? value.split(',') : [];
	}

	function resetForumSearch() {
		$forumSearch.val('');
		$forumPicker.children().prop('hidden', false);
	}

	function updateForumCount() {
		var count = $forumPicker.find('input[type="checkbox"]:checked').length;
		$selectedCount.text(format($app.data('selected-count'), count));
	}

	function loadEditor($row) {
		var ids = forumIdsForRow($row), tagId = $row.data('tag-id');
		$selectedRow.removeClass('is-selected');
		$selectedRow = $row.addClass('is-selected');
		$form.find('input[name="tag_id"]').val(tagId);
		$name.val($row.attr('data-tag-name'));
		$color.val($row.attr('data-tag-color'));
		$colorText.val($row.attr('data-tag-color').toUpperCase());
		$enabled.prop('checked', $row.attr('data-tag-enabled') === '1');
		$forumPicker.find('input[type="checkbox"]').each(function() {
			this.checked = ids.indexOf(this.value) !== -1;
		});
		$('#topic-tag-editor-title').text($app.data('edit-title'));
		resetForumSearch();
		updateForumCount();
		updatePreview();
		validateColor(false);
		$formError.prop('hidden', true).empty();
		setDirty(false);
	}

	function loadNewEditor() {
		$selectedRow.removeClass('is-selected');
		$selectedRow = $();
		$form.find('input[name="tag_id"]').val('0');
		$name.val('');
		$color.val($app.data('default-color'));
		$colorText.val($app.data('default-color'));
		$enabled.prop('checked', true);
		$forumPicker.find('input[type="checkbox"]').prop('checked', false);
		$('#topic-tag-editor-title').text($app.data('create-title'));
		resetForumSearch();
		updateForumCount();
		updatePreview();
		validateColor(false);
		$formError.prop('hidden', true).empty();
		setDirty(false);
	}

	function updateEnabledState($row, enabled) {
		var $toggle = $row.find('.topic-tag-toggle');
		$row.attr('data-tag-enabled', enabled ? '1' : '0').toggleClass('is-disabled', !enabled);
		$toggle.attr('aria-checked', enabled ? 'true' : 'false');
		$toggle.find('.topic-tag-state-current').text($toggle.data(enabled ? 'enabled-label' : 'disabled-label'));
		if ($selectedRow.is($row)) {
			$enabled.prop('checked', enabled);
		}
	}

	function forumNamesFromIds(ids) {
		var names = [];
		$forumPicker.find('input[type="checkbox"]').each(function() {
			if (ids.indexOf(parseInt(this.value, 10)) !== -1) {
				names.push($(this).siblings('span').text());
			}
		});
		return names;
	}

	function populateRow($row, tag) {
		var names = tag.forum_names || forumNamesFromIds(tag.forum_ids),
			previewText = names.join(', '),
			$previewNames = $row.find('.topic-tag-forum-preview');
		$row.attr({
			'data-tag-id': tag.id,
			'data-tag-name': tag.name,
			'data-tag-color': tag.color,
			'data-tag-text-color': tag.text_color,
			'data-forum-ids': tag.forum_ids.join(','),
			'data-search': (tag.name + ' ' + names.join(' ')).toLowerCase()
		});
		$row.data('tag-id', tag.id);
		$row.find('.topic-tag-summary .topic-tag').text(tag.name).css({backgroundColor: tag.color, color: tag.text_color});
		$row.find('.topic-tag-forum-summary strong').text(format($app.data('forum-count'), names.length));
		$previewNames.text(previewText);
		if (previewText) {
			$previewNames.attr('title', previewText);
		} else {
			$previewNames.removeAttr('title');
		}
		$row.find('[data-tag-edit]').attr('href', tag.urls.edit);
		$row.find('.topic-tag-toggle').attr('href', tag.urls.toggle);
		$row.find('.topic-tag-move-up').attr('data-action-url', tag.urls.move_up);
		$row.find('.topic-tag-move-down').attr('data-action-url', tag.urls.move_down);
		$row.find('[data-ajax="tp_delete"]').attr('href', tag.urls.delete);
		updateEnabledState($row, tag.enabled);
	}

	function createRow(tag) {
		var $row = $('#topic-tag-row-template').clone();
		$row.removeAttr('id hidden').removeClass('topic-tag-row-template').attr('draggable', 'false');
		populateRow($row, tag);
		$list.find('.topic-tags-empty').remove();
		$list.append($row);
		phpbb.ajaxify({selector: $row.find('[data-ajax="tp_delete"]'), callback: 'tp_delete'});
		return $row;
	}

	function itemRows() {
		return $list.children('.topic-tag-item');
	}

	function setOrderActionState($action, disabled) {
		$action.attr('aria-disabled', disabled ? 'true' : 'false');
		if (disabled) {
			$action.removeAttr('href').attr('tabindex', '-1');
		} else {
			$action.attr('href', $action.attr('data-action-url')).removeAttr('tabindex');
		}
	}

	function updateOrderActions() {
		var $rows = itemRows(), disableAll = orderSaving || $tagSearch.val().trim() || $statusFilter.val() !== 'all';
		$rows.each(function(index) {
			setOrderActionState($(this).find('.topic-tag-move-up'), disableAll || index === 0);
			setOrderActionState($(this).find('.topic-tag-move-down'), disableAll || index === $rows.length - 1);
		});
	}

	function updateListFilter() {
		var query = $tagSearch.val().trim().toLowerCase(), status = $statusFilter.val(), visible = 0,
			filterActive = query !== '' || status !== 'all', $rows = itemRows();
		$rows.each(function() {
			var $row = $(this), enabled = $row.attr('data-tag-enabled') === '1',
				matchesText = String($row.attr('data-search') || '').toLowerCase().indexOf(query) !== -1,
				matchesStatus = status === 'all' || (status === 'enabled' && enabled) || (status === 'disabled' && !enabled),
				show = matchesText && matchesStatus;
			$row.prop('hidden', !show).attr('draggable', 'false');
			$row.find('.topic-tag-drag').prop('disabled', filterActive);
			if (show) {
				visible++;
			}
		});
		$resultCount.text(format($app.data('result-count'), visible));
		$noResults.prop('hidden', !($rows.length && visible === 0));
		$orderHint.prop('hidden', !filterActive);
		updateOrderActions();
		if (!$rows.length && !$list.find('.topic-tags-empty').length) {
			$('<div class="topic-tags-empty">').text($app.data('empty')).appendTo($list);
		}
	}

	function currentOrder() {
		return itemRows().map(function() {
			return parseInt($(this).attr('data-tag-id'), 10);
		}).get();
	}

	function restoreOrder(order) {
		$.each(order, function(index, id) {
			var $row = itemRows().filter(function() {
				return parseInt($(this).attr('data-tag-id'), 10) === id;
			}).first();
			$list.append($row);
		});
	}

	function persistOrder(oldOrder) {
		var data = formTokenData(), $rows = itemRows(), $actions = $rows.find('.topic-tag-actions');
		orderSaving = true;
		updateOrderActions();
		data.action = 'reorder';
		data.tag_ids = currentOrder();
		$actions.addClass('is-saving');
		$rows.find('.topic-tag-drag').prop('disabled', true);
		$.ajax({url: $app.data('action'), type: 'POST', data: data, cache: false})
			.done(function(response) {
				if (response.success) {
					showNotice(response.message, false);
				}
			})
			.fail(function(xhr) {
				restoreOrder(oldOrder);
				requestError(xhr);
			})
			.always(function() {
				orderSaving = false;
				$actions.removeClass('is-saving');
				updateListFilter();
			});
	}

	function toggleRow($row, desired, source) {
		var data = formTokenData(), $toggle = $row.find('.topic-tag-toggle');
		if ($toggle.hasClass('is-saving')) {
			return;
		}
		data.action = 'toggle';
		data.tag_id = $row.attr('data-tag-id');
		data.enabled = desired ? 1 : 0;
		$toggle.addClass('is-saving');
		$.ajax({url: $app.data('action'), type: 'POST', data: data, cache: false})
			.done(function(response) {
				if (response.success) {
					updateEnabledState($row, response.enabled);
					updateListFilter();
					showNotice(response.message, false);
				}
			})
			.fail(function(xhr) {
				if (source === 'editor') {
					$enabled.prop('checked', !desired);
				}
				requestError(xhr);
			})
			.always(function() {
				$toggle.removeClass('is-saving');
			});
	}

	phpbb.addAjaxCallback('tp_delete', function(response) {
		var $row = $(this).closest('.topic-tag-item');
		if (!response.success) {
			return;
		}
		if ($selectedRow.is($row)) {
			loadNewEditor();
		}
		$row.remove();
		updateListFilter();
		showNotice(response.message, false);
	});

	$list[0].addEventListener('click', function(event) {
		var $delete = $(event.target).closest('[data-ajax="tp_delete"]'), $row;
		if (!$delete.length) {
			return;
		}
		$row = $delete.closest('.topic-tag-item');
		if (dirty && $selectedRow.is($row)) {
			if (!window.confirm($app.data('discard'))) {
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		}
	}, true);

	$list.on('click', '[data-tag-edit]', function(event) {
		var $row = $(this).closest('.topic-tag-item');
		event.preventDefault();
		if ($selectedRow.is($row)) {
			return;
		}
		confirmDiscard(function() {
			loadEditor($row);
			if (window.matchMedia && window.matchMedia('(max-width: 980px)').matches) {
				document.querySelector('.topic-tag-inspector').scrollIntoView({behavior: 'smooth', block: 'start'});
			}
		});
	});

	$('.topic-tag-new').on('click', function() {
		confirmDiscard(function() {
			loadNewEditor();
			$name.trigger('focus');
		});
	});

	$('#topic-tag-cancel').on('click', function(event) {
		event.preventDefault();
		confirmDiscard(function() {
			loadNewEditor();
		});
	});

	$list.on('click', '.topic-tag-toggle', function(event) {
		var $row = $(this).closest('.topic-tag-item');
		event.preventDefault();
		toggleRow($row, $row.attr('data-tag-enabled') !== '1', 'row');
	});

	$list.on('keydown', '.topic-tag-toggle', function(event) {
		if (event.key === ' ' || event.keyCode === 32) {
			event.preventDefault();
			$(this).trigger('click');
		}
	});

	$enabled.on('change', function() {
		if ($selectedRow.length) {
			toggleRow($selectedRow, this.checked, 'editor');
		} else {
			setDirty(true);
		}
	});

	$name.on('input', function() {
		setDirty(true);
		updatePreview();
	});

	$color.on('input change', function() {
		$colorText.val(this.value.toUpperCase());
		setDirty(true);
		updatePreview();
		validateColor(false);
	});

	$colorText.on('input', function() {
		setDirty(true);
		updatePreview();
		validateColor(false);
	}).on('blur', function() {
		var value = normalizeColor(this.value);
		if (/^#[0-9A-F]{6}$/.test(value)) {
			this.value = value;
			$color.val(value);
		}
		validateColor(true);
		updatePreview();
	});

	$forumPicker.on('change', 'input[type="checkbox"]', function() {
		setDirty(true);
		updateForumCount();
	});

	$forumSearch.on('input', function() {
		var query = this.value.trim().toLowerCase();
		$forumPicker.children().each(function() {
			$(this).prop('hidden', String($(this).attr('data-forum-name') || '').toLowerCase().indexOf(query) === -1);
		});
	});

	$('#topic-tag-select-visible').on('click', function() {
		$forumPicker.find('.topic-tag-forum-option:not([hidden]) input').prop('checked', true);
		setDirty(true);
		updateForumCount();
	});

	$('#topic-tag-clear-forums').on('click', function() {
		$forumPicker.find('input[type="checkbox"]').prop('checked', false);
		setDirty(true);
		updateForumCount();
	});

	$tagSearch.add($statusFilter).on('input change', updateListFilter);

	$list.on('click', '.topic-tag-move-up, .topic-tag-move-down', function(event) {
		var $link = $(this), $row = $link.closest('.topic-tag-item'), oldOrder = currentOrder(), $sibling;
		if ($link.attr('aria-disabled') === 'true' || orderSaving || $tagSearch.val().trim() || $statusFilter.val() !== 'all') {
			event.preventDefault();
			return;
		}
		event.preventDefault();
		$sibling = $link.hasClass('topic-tag-move-up') ? $row.prev('.topic-tag-item') : $row.next('.topic-tag-item');
		if (!$sibling.length) {
			return;
		}
		if ($link.hasClass('topic-tag-move-up')) {
			$row.insertBefore($sibling);
		} else {
			$row.insertAfter($sibling);
		}
		persistOrder(oldOrder);
	});

	$list.on('mousedown', '.topic-tag-drag', function() {
		if (!orderSaving && !$tagSearch.val().trim() && $statusFilter.val() === 'all') {
			$(this).closest('.topic-tag-item').attr('draggable', 'true');
		}
	});

	$list.on('dragstart', '.topic-tag-item', function(event) {
		if ($(this).attr('draggable') !== 'true') {
			event.preventDefault();
			return;
		}
		draggedRow = this;
		dropCommitted = false;
		previousOrder = currentOrder();
		$(this).addClass('is-dragging');
		dragImage = $('<div class="topic-tag-drag-image">').text($(this).attr('data-tag-name')).appendTo(document.body)[0];
		event.originalEvent.dataTransfer.effectAllowed = 'move';
		event.originalEvent.dataTransfer.setData('text/plain', $(this).attr('data-tag-id'));
		if (event.originalEvent.dataTransfer.setDragImage) {
			event.originalEvent.dataTransfer.setDragImage(dragImage, 20, 20);
		}
	});

	$list.on('dragover', '.topic-tag-item', function(event) {
		var rect;
		if (!draggedRow || this === draggedRow) {
			return;
		}
		event.preventDefault();
		event.originalEvent.dataTransfer.dropEffect = 'move';
		rect = this.getBoundingClientRect();
		if (event.originalEvent.clientY < rect.top + rect.height / 2) {
			$(draggedRow).insertBefore(this);
		} else {
			$(draggedRow).insertAfter(this);
		}
	});

	$list.on('dragover', function(event) {
		if (draggedRow) {
			event.preventDefault();
			event.originalEvent.dataTransfer.dropEffect = 'move';
		}
	});

	$list.on('drop', function(event) {
		if (!draggedRow) {
			return;
		}
		event.preventDefault();
		event.originalEvent.dataTransfer.dropEffect = 'move';
		dropCommitted = true;
	});

	$list.on('dragend', '.topic-tag-item', function() {
		var changed = previousOrder.join(',') !== currentOrder().join(',');
		$(this).removeClass('is-dragging').attr('draggable', 'false');
		if (dragImage) {
			document.body.removeChild(dragImage);
			dragImage = null;
		}
		draggedRow = null;
		if (!dropCommitted) {
			restoreOrder(previousOrder);
		} else if (changed) {
			persistOrder(previousOrder);
		}
		dropCommitted = false;
	});

	$form.on('submit', function(event) {
		var $submit = $form.find('input[type="submit"]'), creating, data;
		event.preventDefault();
		$formError.prop('hidden', true).empty();
		if (!this.checkValidity()) {
			this.reportValidity();
			return;
		}
		if (!validateColor(true)) {
			$colorText.trigger('focus');
			return;
		}
		$colorText.val(normalizeColor($colorText.val()));
		$color.val($colorText.val());
		creating = parseInt($form.find('input[name="tag_id"]').val(), 10) === 0;
		data = $form.serialize() + '&submit=1';
		$submit.prop('disabled', true);
		$.ajax({url: $form.attr('action'), type: 'POST', data: data, cache: false})
			.done(function(response) {
				var $row;
				if (!response.success) {
					return;
				}
				if (creating) {
					$row = createRow(response.tag);
					loadNewEditor();
				} else {
					$row = $selectedRow;
					populateRow($row, response.tag);
					loadEditor($row);
				}
				updateListFilter();
				showNotice(response.message, false);
				if (creating) {
					$name.trigger('focus');
				}
			})
			.fail(function(xhr) {
				var response = responseData(xhr), field;
				$formError.text(response.message || $app.data('request-failed')).prop('hidden', false);
				field = response.field ? document.getElementById(response.field) : null;
				if (field) {
					field.focus();
				}
			})
			.always(function() {
				$submit.prop('disabled', false);
			});
	});

	$(window).on('beforeunload', function() {
		if (dirty) {
			return $app.data('discard');
		}
	});

	itemRows().attr('draggable', 'false');
	updateListFilter();
	updateForumCount();
	updatePreview();
	validateColor(false);
	var initialId = parseInt($form.find('input[name="tag_id"]').val(), 10);
	if (initialId) {
		var $initial = itemRows().filter(function() {
			return parseInt($(this).attr('data-tag-id'), 10) === initialId;
		}).first();
		if ($initial.length) {
			$selectedRow = $initial.addClass('is-selected');
		}
	}
})(jQuery);
