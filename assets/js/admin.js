(function ($) {
	'use strict';

	var config = window.subPodAdmin;
	// wp_localize_script は値を文字列にして渡すので、数値に戻してから計算する
	var chunkSize = parseInt(config.chunkSize, 10);
	var busy = false;
	var restoreFile = null;
	var STATE_KEY = 'sub_pod_accordion_states';
	var UPLOAD_SHARE = 20;
	var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var motionDuration = reducedMotion ? 0 : 120;
	var motionDurationLong = reducedMotion ? 0 : 200;

	function showMessage(type, text) {
		var box = document.getElementById('sub-pod-message');
		var notice = document.createElement('div');
		var paragraph = document.createElement('p');
		notice.className = 'notice notice-' + type;
		paragraph.textContent = text;
		notice.appendChild(paragraph);
		box.replaceChildren(notice);
		box.scrollIntoView({ block: 'nearest' });
		return notice;
	}

	function showConfirm(message, onConfirm) {
		$('.nau-confirm-dialog').remove();

		var $dialog = $('<div>').addClass('nau-confirm-dialog');
		var $overlay = $('<div>').addClass('nau-confirm-overlay');
		var $box = $('<div>').addClass('nau-confirm-box');
		var $title = $('<h3>').text('確認');
		var $message = $('<p>').text(message);
		var $buttons = $('<div>').addClass('nau-confirm-buttons');
		var $yes = $('<button type="button">').addClass('button button-primary nau-confirm-yes').text('はい');
		var $no = $('<button type="button">').addClass('button nau-confirm-no').text('いいえ');

		$buttons.append($yes).append($no);
		$box.append($title).append($message).append($buttons);
		$dialog.append($overlay).append($box);
		$('body').append($dialog);

		$yes.on('click', function () {
			$dialog.remove();
			onConfirm();
		});
		$no.add($overlay).on('click', function () {
			$dialog.remove();
		});
		// 取り返しのつかない操作なので、Enter でそのまま進まないようにする
		$no.trigger('focus');
	}

	function setBusy(value) {
		busy = value;
		$('#sub-pod-backup, #sub-pod-restore-submit').prop('disabled', value);
	}

	function showProgress(id, text, percent) {
		var box = document.getElementById(id);
		box.hidden = false;
		box.querySelector('.sub-pod-progress-status').textContent = text;
		box.querySelector('.sub-pod-progress-bar').style.width = percent + '%';
		box.querySelector('.sub-pod-progress-percentage').textContent = percent + '%';
		box.querySelector('.sub-pod-progress-bar-wrapper').setAttribute('aria-valuenow', percent);
	}

	function hideProgress(id) {
		document.getElementById(id).hidden = true;
	}

	function post(action, fields) {
		var body = fields instanceof FormData ? fields : new FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);
		return fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
	}

	function postJson(action, fields) {
		return post(action, fields).then(function (response) {
			return response.json();
		}).then(function (json) {
			if (!json.success) {
				throw new Error(json.data && json.data.message ? json.data.message : '処理に失敗しました。');
			}
			return json.data;
		});
	}

	// サーバーから 1 行ずつ届く進捗を読み、完了の行を返す。完了の行が来ないまま切れたら失敗として扱う
	function readStream(response, onMessage) {
		var type = response.headers.get('Content-Type') || '';
		if (type.indexOf('application/json') !== -1) {
			return response.json().then(function (json) {
				throw new Error(json.data && json.data.message ? json.data.message : '処理に失敗しました。');
			});
		}

		var reader = response.body.getReader();
		var decoder = new TextDecoder();
		var buffer = '';

		function handle(line) {
			var data;
			try {
				data = JSON.parse(line);
			} catch (e) {
				// 別のプラグインなどの出力が混ざっても、処理は続いているので止めない
				return null;
			}
			if (data.error) {
				var failure = new Error(data.error);
				failure.logUrl = data.logUrl;
				throw failure;
			}
			if (data.message) {
				onMessage(data.message, data.percent);
			}
			return data.done ? data : null;
		}

		function pump() {
			return reader.read().then(function (result) {
				buffer += decoder.decode(result.value || new Uint8Array(), { stream: !result.done });
				var lines = buffer.split('\n');
				buffer = lines.pop();
				for (var i = 0; i < lines.length; i++) {
					if (lines[i] !== '') {
						var done = handle(lines[i]);
						if (done) {
							return done;
						}
					}
				}
				if (result.done) {
					throw new Error('処理が途中で終了しました。サーバーのエラーログを確認してください。');
				}
				return pump();
			});
		}

		return pump();
	}

	function loadAccordionStates() {
		try {
			return JSON.parse(window.localStorage.getItem(STATE_KEY)) || {};
		} catch (error) {
			return {};
		}
	}

	function saveAccordionState(section, expanded) {
		try {
			var states = loadAccordionStates();
			states[section] = expanded;
			window.localStorage.setItem(STATE_KEY, JSON.stringify(states));
		} catch (error) {
			// 保存できない環境では、開閉状態を覚えないだけ
		}
	}

	function initAccordion() {
		var states = loadAccordionStates();

		$('.nau-accordion-section').each(function () {
			var section = this.dataset.section;
			var $header = $(this).children('.nau-accordion-header');
			var $content = $(this).children('.nau-accordion-content');
			var expanded = section in states ? states[section] : $header.attr('aria-expanded') === 'true';

			$header.attr('aria-expanded', expanded);
			$content.attr('aria-hidden', !expanded).toggle(expanded);

			$header.on('click', function () {
				var next = $header.attr('aria-expanded') !== 'true';
				$header.attr('aria-expanded', next);
				$content.attr('aria-hidden', !next);
				if (next) {
					$content.slideDown(motionDuration);
				} else {
					$content.slideUp(motionDuration);
				}
				saveAccordionState(section, next);
			});
		});
	}

	// チェックを入れた保存先の設定だけを開く
	function initDestinations() {
		[['#sub-pod-directory-enabled', '#sub-pod-directory-settings'], ['#sub-pod-r2-enabled', '#sub-pod-r2-settings']].forEach(function (pair) {
			$(pair[0]).on('change', function () {
				if (this.checked) {
					$(pair[1]).slideDown(motionDurationLong);
				} else {
					$(pair[1]).slideUp(motionDurationLong);
				}
			});
		});
	}

	function closeTooltip($trigger) {
		$trigger.closest('.nau-tooltip-wrapper').removeClass('show');
		$trigger.attr('aria-expanded', 'false');
	}

	function initTooltips() {
		$(document).on('click', '.nau-tooltip-trigger', function (event) {
			// ラベルの中にあっても、チェックボックスを切り替えないようにする
			event.preventDefault();
			event.stopPropagation();

			var $wrapper = $(this).closest('.nau-tooltip-wrapper');
			var isOpen = $wrapper.hasClass('show');

			closeTooltip($('.nau-tooltip-trigger'));
			if (!isOpen) {
				$wrapper.addClass('show');
				$(this).attr('aria-expanded', 'true');
			}
		});

		$(document).on('keydown', '.nau-tooltip-trigger', function (event) {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				$(this).trigger('click');
			} else if (event.key === 'Escape') {
				closeTooltip($(this));
			}
		});

		$(document).on('click', function (event) {
			if (!$(event.target).closest('.nau-tooltip-wrapper').length) {
				closeTooltip($('.nau-tooltip-trigger'));
			}
		});
	}

	function runBackup() {
		var id = 'sub-pod-backup-progress';
		setBusy(true);
		$('#sub-pod-log').hide();
		showProgress(id, 'バックアップを開始しています', 0);

		post('sp_backup').then(function (response) {
			return readStream(response, function (message, percent) {
				showProgress(id, message, percent);
			});
		}).then(function (result) {
			var text = 'バックアップが完了しました。';
			if (result.skippedLinks > 0) {
				text += ' シンボリックリンク ' + result.skippedLinks + ' 件は含めていません: ' + result.skippedNames.join('、');
			}
			if (result.skippedObjects.length > 0) {
				text += ' データベースの機能は含めていません: ' + result.skippedObjects.join('、');
			}
			showMessage('success', text);
			$('#sub-pod-log').attr('href', result.logUrl).show();
			if (result.downloadUrl) {
				window.location.href = result.downloadUrl;
			}
		}).catch(function (error) {
			showMessage('error', error.message);
			if (error.logUrl) {
				$('#sub-pod-log').attr('href', error.logUrl).show();
			}
		}).finally(function () {
			setBusy(false);
			hideProgress(id);
		});
	}

	// 保存できないときの理由を返す。文字数と繰り返しはサーバーでも確かめる
	function passwordProblem(password, confirm) {
		if (password === '') {
			return '';
		}
		if (password !== confirm) {
			return '暗号化パスワードと確認用の入力が一致しません。';
		}
		// 判定に使う辞書は後から読み込まれる
		if (typeof window.zxcvbn !== 'function') {
			return 'パスワードの強さを確認する準備ができていません。少し待ってから、もう一度保存してください。';
		}
		var disallowed = wp.passwordStrength.userInputDisallowedList();
		// 0〜2 は WordPress の画面で「非常に脆弱」「脆弱」と表示される強さ
		if (wp.passwordStrength.meter(password, disallowed, confirm) < 3) {
			return '暗号化パスワードが弱すぎます。よくある単語や並びを避けて、長めにしてください。';
		}
		return '';
	}

	function saveSettings(event) {
		event.preventDefault();
		var form = event.currentTarget;

		var problem = passwordProblem(form.elements.password.value, form.elements.password_confirm.value);
		if (problem) {
			showMessage('error', problem);
			return;
		}

		postJson('sp_save_settings', new FormData(form)).then(function (data) {
			// 保存できた時点でパスワードは設定済みなので、未設定の警告も一緒に消す
			$('.sub-pod-password-notice').remove();
			$(showMessage('success', data.message)).delay(3000).fadeOut(motionDurationLong, function () {
				$(this).remove();
			});
			['r2_secret_key', 'password'].forEach(function (name) {
				var field = form.elements[name];
				if (field.value !== '') {
					field.value = '';
					field.placeholder = '設定済み(変更するときだけ入力)';
					$(field).siblings('.sub-pod-secret-status').prop('hidden', false);
				}
			});
			form.elements.password_confirm.value = '';
		}).catch(function (error) {
			showMessage('error', error.message);
		});
	}

	function uploadChunks(file, onProgress) {
		function send(offset) {
			if (offset >= file.size) {
				return Promise.resolve();
			}
			var end = Math.min(offset + chunkSize, file.size);
			if (end <= offset) {
				return Promise.reject(new Error('サーバーのアップロード上限を確認してください。'));
			}
			var body = new FormData();
			body.append('offset', offset);
			body.append('chunk', file.slice(offset, end), 'chunk');
			return postJson('sp_upload_chunk', body).then(function () {
				onProgress(end);
				return send(end);
			});
		}

		return postJson('sp_upload_start').then(function () {
			return send(0);
		});
	}

	function formatSize(bytes) {
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var index = 0;
		while (bytes >= 1024 && index < units.length - 1) {
			bytes /= 1024;
			index++;
		}
		return (index === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[index];
	}

	// ここでは選ぶだけで、送るのは「復元する」を押してから
	function setRestoreFile(file) {
		if (file && !/\.enc$/i.test(file.name)) {
			showMessage('error', '暗号化されたバックアップファイル(.enc)を選んでください。');
			return;
		}

		restoreFile = file;
		document.querySelector('.sub-pod-selected-file').textContent = file ? file.name + '(' + formatSize(file.size) + ')' : '';
		$('#sub-pod-restore-placeholder').toggle(!file);
		$('#sub-pod-restore-selected').toggle(!!file);
	}

	function initRestoreDrop() {
		var input = document.getElementById('sub-pod-restore-file');
		var $area = $('#sub-pod-restore-drop');

		$('#sub-pod-restore-placeholder').on('click', function () {
			if (!busy) {
				input.click();
			}
		});
		input.addEventListener('change', function () {
			if (input.files[0]) {
				setRestoreFile(input.files[0]);
			}
			input.value = '';
		});
		$('#sub-pod-restore-remove').on('click', function () {
			if (!busy) {
				setRestoreFile(null);
			}
		});

		$area.on('dragover', function (event) {
			event.preventDefault();
			$area.addClass('nau-drag-over');
		});
		$area.on('dragleave', function () {
			$area.removeClass('nau-drag-over');
		});
		$area.on('drop', function (event) {
			event.preventDefault();
			$area.removeClass('nau-drag-over');
			var files = event.originalEvent.dataTransfer.files;
			if (!busy && files.length > 0) {
				setRestoreFile(files[0]);
			}
		});
	}

	function runRestore() {
		if (busy) {
			return;
		}

		var file = restoreFile;
		var adminField = document.getElementById('sub-pod-restore-admin');
		var encryptionField = document.getElementById('sub-pod-restore-encryption');

		if (!file) {
			showMessage('error', 'バックアップファイルを選んでください。');
			return;
		}
		if (encryptionField.value === '') {
			showMessage('error', '暗号化パスワードを入力してください。');
			return;
		}
		if (adminField.value === '') {
			showMessage('error', '今のログインパスワードを入力してください。');
			return;
		}
		if (!document.getElementById('sub-pod-restore-confirm').checked) {
			showMessage('error', '今のサイトが置き換わることを確認してください。');
			return;
		}

		showConfirm('「' + file.name + '」で今のサイトを置き換えます。データベースもファイルも置き換わり、元に戻せません。復元してよろしいですか?', function () {
			startRestore(file, adminField.value, encryptionField.value);
		});
	}

	function startRestore(file, adminPassword, encryptionPassword) {
		var id = 'sub-pod-restore-progress';

		setBusy(true);
		showProgress(id, 'ファイルをアップロードしています', 0);

		// アップロードも含めて 1 本の棒で見せる(最初の 2 割がアップロード)
		uploadChunks(file, function (sent) {
			showProgress(id, 'ファイルをアップロードしています', Math.floor(sent / file.size * UPLOAD_SHARE));
		}).then(function () {
			var body = new FormData();
			body.append('admin_password', adminPassword);
			body.append('encryption_password', encryptionPassword);
			body.append('total', file.size);
			return post('sp_restore', body);
		}).then(function (response) {
			return readStream(response, function (message, percent) {
				showProgress(id, message, UPLOAD_SHARE + Math.floor(percent * (100 - UPLOAD_SHARE) / 100));
			});
		}).then(function (result) {
			if (result.skippedLinks > 0) {
				window.alert('復元しました。このバックアップにはシンボリックリンク ' + result.skippedLinks + ' 件が含まれていません。必要なら作り直してください: ' + result.skippedNames.join('、'));
			}
			window.location.href = result.loginUrl;
		}).catch(function (error) {
			showMessage('error', error.message);
			setBusy(false);
			hideProgress(id);
		});
	}

	function discardLeftover() {
		showConfirm('一時ファイルを消します。元のサイトのデータも一緒に消え、元に戻せません。よろしいですか?', function () {
			postJson('sp_discard_leftover').then(function () {
				window.location.reload();
			}).catch(function (error) {
				showMessage('error', error.message);
			});
		});
	}

	$(function () {
		initAccordion();
		initDestinations();
		initTooltips();
		initRestoreDrop();
		$('#sub-pod-discard-leftover').on('click', discardLeftover);
		$('#sub-pod-backup').on('click', runBackup);
		$('#sub-pod-settings').on('submit', saveSettings);
		$('#sub-pod-restore-submit').on('click', runRestore);
		// 復元の入力欄で Enter を押しても、設定の保存にならないようにする
		$('#accordion-restore input').on('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
			}
		});
	});
})(jQuery);
