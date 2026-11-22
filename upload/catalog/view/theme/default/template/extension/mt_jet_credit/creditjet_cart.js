jQuery(document).ready(function($) {
	let card;
	card = 0;


	let jetActiveQuote = null;
	let jetOpenPending = false;
	let jetPreviousQuote = '';
	let jetIntentId = '';
	let jetIntentPending = false;
	let jetIntentQueued = false;
	let jetFormGeneration = 0;
	let jetCartLineage = '';
	const jetInitialSchemes = [null, null];
	const jetSchemeMessage = 'За избраните условия продуктите в количката не могат да бъдат закупени заедно на изплащане. Моля, изберете друг срок или условия.\n\nАко желаете, можете да закупите на изплащане всеки продукт поотделно.';
	const jetStaleMessage = 'Условията на количката са променени. Заявката за финансиране не е изпратена. Моля, обновете количката и направете ново изчисление на финансирането.';
	const jetCanonicalDown = () => {
		const raw = String($('#jet_parva').val() || '').trim();
		if (raw !== '' && !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(raw)) return null;
		const number = raw === '' ? 0 : Number(raw);
		return Number.isFinite(number) && number >= 0 ? number.toFixed(2) : null;
	};
	const jetQuoteState = () => JSON.stringify([$('#jet_price').val(), $('#jet_vnoski').val(), card,
		jetCanonicalDown(), ...['jet_products','jet_products_qt','jet_products_pr','jet_products_vr','jet_cart_rows'].map(id => $('#' + id).val())]);
	const jetQuoteCurrent = () => jetActiveQuote && jetIntentId && jetActiveQuote.intent === jetIntentId && jetActiveQuote.state === jetQuoteState();
	const jetInvalidateQuote = () => {
		jetActiveQuote = null;
		$('#buy_jetcredit').prop('disabled', true);
		$('#buy2_jetcredit').prop('disabled', true);
	};
	let jetSchemeNoticeState = null;
	let jetCloseSchemeDialog = null;
	const previousInformation = document.getElementById('jet_alert_box');
	if (previousInformation && typeof previousInformation.jetCloseSchemeDialog === 'function') previousInformation.jetCloseSchemeDialog(false);
	const jetSchemeStatus = (invalid) => {
		const state = jetQuoteState();
		if (invalid && jetSchemeNoticeState === state) return;
		if (jetCloseSchemeDialog) jetCloseSchemeDialog(false);
		if (!invalid) return;
		jetSchemeNoticeState = state;
		jetShowCustomAlert(jetSchemeMessage, false, true);
	};
	const jetClearResults = () => {
		for (const field of ['priceall', 'total_credit_price', 'vnoska_popup', 'gpr', 'glp', 'obshto']) {
			$('#jet_' + field).text('');
			$('#jet_' + field + '_second').text('');
		}
	};
	const jetSchemeRejected = () => {
		jetInvalidateQuote();
		jetClearResults();
		if (jetOpenPending) $('#jet-product-popup-container').show();
		jetOpenPending = false;
		jetSchemeStatus(true);
	};
	const jetSetEntry = (index, scheme) => {
		jetInitialSchemes[index] = scheme;
		const suffix = index ? '_card' : '';
		const button = $('#btn_jet' + suffix);
		const table = button.closest('.jet_table_img');
		if (scheme) {
			button.show(); table.show();
			$('#jet_vnoski_text' + suffix).text(scheme.jet_vnoski);
			$('#jet_vnoska' + suffix).text(scheme['jet_vnoska' + suffix]);
			$('#jet_vnoska' + suffix + '_second').text(scheme['jet_vnoska' + suffix + '_second']);
		} else { button.hide(); table.hide(); }
	};
	const jetSubmission = { locked: false, needsReview: false, alert: null };
	const jetSubmissionUncertain = 'Резултатът от изпращането не е потвърден. Моля, свържете се с магазина за проверка.';
	const jetSubmissionLoading = (loading) => {
		jetPopupLoading(loading);
		$('#jet-product-popup-container .jet_offer_loader > span').text(loading ? 'Изпращане на заявката. Моля, изчакайте…' : 'Преизчисляване…');
	};
	const jetLockSubmission = () => {
		jetSubmission.locked = true;
		clearTimeout(jetDownpaymentTimer);
		++jetCalculationRevision;
		jetSubmissionLoading(true);
		$('#buy2_jetcredit').prop('disabled', true).attr('aria-busy', 'true');
		$('#jet_alert_overlay').appendTo('body').removeClass('show').attr('role', 'status')
			.text('Изпращане на заявката. Моля, изчакайте…');
	};
	const jetUnlockSubmission = () => {
		jetSubmissionLoading(false);
		jetSubmission.locked = false;
		jetSubmission.alert = null;
		$('#jet_alert_overlay').text('').removeAttr('role').removeClass('show');
		$('#buy2_jetcredit').removeAttr('aria-busy');
		changeBtnJetcreditBuy();
	};
	const jetBlockSubmissionInteraction = (event) => {
		if (!jetSubmission.locked || (jetSubmission.alert && jetSubmission.alert.contains(event.target))) return;
		if (event.cancelable) event.preventDefault();
		event.stopImmediatePropagation();
	};
	['click', 'dblclick', 'pointerdown', 'pointerup', 'mousedown', 'mouseup', 'touchstart', 'touchend',
		'keydown', 'keyup', 'keypress', 'submit', 'input', 'change'].forEach((type) => {
		window.addEventListener(type, jetBlockSubmissionInteraction, { capture: true, passive: false });
	});
	window.addEventListener('focusin', (event) => {
		if (!jetSubmission.locked || (jetSubmission.alert && jetSubmission.alert.contains(event.target))) return;
		jetBlockSubmissionInteraction(event);
		if (jetSubmission.alert) jetSubmission.alert.querySelector('button').focus();
		else document.getElementById('buy2_jetcredit').blur();
	}, true);

	let jetCalculationRevision = 0;
	let jetDownpaymentTimer;
	const jetDownpaymentValue = () => {
		const value = String($('#jet_parva').val() || '').trim();
		if (value === '') return '0';
		const amount = parseFloat(value);
		return Number.isNaN(amount) ? value : String(amount);
	};
	let jetLastDownpayment = jetDownpaymentValue();
	const jetPopupLoading = (loading) => {
		const popup = $('#jet-product-popup-container');
		if (loading && !popup.find('.jet_offer_loader').length) {
			const content = popup.find('.modalpayment-content_jet').first();
			$('<div>', { class: 'jet_offer_loader', role: 'status', 'aria-live': 'polite' })
				.append($('<span>', { text: 'Преизчисляване…' }))
				.appendTo(content.length ? content : popup);
		}
		popup.toggleClass('jet_offer_loading', loading).attr('aria-busy', loading ? 'true' : 'false');
	};
	const jetBeginCalculation = () => {
		jetSchemeStatus(false);
		if (jetSchemeNoticeState !== jetQuoteState()) jetSchemeNoticeState = null;
		jetInvalidateQuote();
		clearTimeout(jetDownpaymentTimer);
		jetLastDownpayment = jetDownpaymentValue();
		const revision = ++jetCalculationRevision;
		jetPopupLoading(true);
		return revision;
	};
	const jetFinishCalculation = (revision) => {
		if (revision === jetCalculationRevision) jetPopupLoading(false);
	};
	const jetScheduleDownpayment = () => {
		const value = jetDownpaymentValue();
		if (value === jetLastDownpayment) return;
		jetLastDownpayment = value;
		jetSchemeStatus(false);
		if (jetSchemeNoticeState !== jetQuoteState()) jetSchemeNoticeState = null;
		jetInvalidateQuote();
		++jetCalculationRevision;
		clearTimeout(jetDownpaymentTimer);
		jetDownpaymentTimer = setTimeout(() => jetCalculate(false), 400);
	};

	/* function */
	const jetOpen = (selectedCard) => {
		if (jetSubmission.locked || jetSubmission.needsReview || !jetInitialSchemes[selectedCard]) return;
		if (jetOpenPending && card === selectedCard) return;
		++jetFormGeneration;
		jetIntentId = ''; jetPreviousQuote = ''; jetCartLineage = '';
		jetIntentPending = false; jetIntentQueued = false;
		jetSchemeNoticeState = null;
		card = selectedCard;
		$('#jet_vnoski').val(jetInitialSchemes[card].jet_vnoski);
		$('#jet_parva').val(0);
		$('#jet_step_1').show(); $('#jet_step_2').hide();
		jetClearResults(); jetSchemeStatus(false);
		jetOpenPending = true;
		jetCalculate();
	};
	const jetLoad = () => {
		if ($('#btn_jet').length !== 0) {
			$('#jet_step_1').css('display', 'block');
			$('#jet_step_2').css('display', 'none');
			$('#btn_jet').on('click', function(event) {
				jetOpen(0);
			});
			if ($('#btn_jet_card').length !== 0) {
				$('#btn_jet_card').on('click', function(event) {
					jetOpen(1);
				});
			}
			$('#back_jetcredit').on('click', function(event) {
				jetClose();
			});
			$('#close_jetcredit').on('click', function(event) {
				jetClose();
			});
			$('#buy_jetcredit').on('click', function(event) {
				if (!jetQuoteCurrent()) return;
				$('#jet_parva').val(jetActiveQuote.downpayment);
				let _taxa = parseFloat($('#jet_vnoska_popup').text());
				if (_taxa >= 20) {
					$('#jet_step_1').css('display', 'none');
					$('#jet_step_2').css('display', 'block');
				} else {
					$('#jet_alert_overlay').addClass('show');
					jetShowCustomAlert('Месечната вноска трябва да надхвърля сумата от 20 евро!', false);
				}
			});
			$('#back2_jetcredit').on('click', function(event) {
				$('#jet_step_2').css('display', 'none');
				$('#jet_step_1').css('display', 'block');
			});
			$('#jet_egn').on('input', function(event) {
				const input = $(this).val();
				const regex = /^[0-9]{0,10}$/;
				if (!regex.test(input)) {
					$(this).val(input.slice(0, -1));
				}
			});
			$('#jet_phone').on('input', function(event) {
				const input = $(this).val();
				const regex = /^[+0-9]+$/;
				if (!regex.test(input)) {
					$(this).val(input.slice(0, -1));
				}
			});
			$('#buy2_jetcredit').on('click', function(event) {
				if (jetQuoteCurrent() && checkForm()) {
					jetSend();
				}
			});
			$('.jet_input_text_active').on('click', function(event) {
				if ($(this).hasClass('error')) {
					$(this).removeClass('error');
				}
			});
			jetSetEntry(0, null); jetSetEntry(1, null);
			jetCalculate(true);
		}
	}

	const jetCalculate = (discovery = false) => {
		if (jetSubmission.locked) return;
		if (!jetIntentId && jetIntentPending) { jetIntentQueued = true; jetInvalidateQuote(); return; }
		const revision = jetBeginCalculation();
		const jetPriceall = parseFloat($('#jet_price').val());
		const canonicalDown = jetCanonicalDown();
		const jetDownpayment = canonicalDown === null ? NaN : Number(canonicalDown);
		const state = jetQuoteState();
		jetHideOptions401();
		jetHideOptions601();
		if (jetPriceall >= 401) {
			jetShowOptions401();
		}
		if (jetPriceall >= 601) {
			jetShowOptions601();
		}
		if (Number.isFinite(jetPriceall) && jetPriceall > 0 && Number.isFinite(jetDownpayment) && jetDownpayment < Number(jetPriceall.toFixed(2))) {
			const jetFormData = new FormData();
			if (discovery) jetFormData.append('jet_cart_discovery', '1');
			jetFormData.append('jet_priceall', jetPriceall.toFixed(2));
			jetFormData.append('jet_intent_id', jetIntentId);
			jetFormData.append('jet_parva', canonicalDown);
			jetFormData.append('jet_downpayment_raw', String($('#jet_parva').val() || ''));
			jetFormData.append('jet_card', card);
			jetFormData.append('jet_previous_quote', jetPreviousQuote);
			jetFormData.append('jet_cart_lineage', jetCartLineage);
			jetFormData.append('jet_cart_rows', $('#jet_cart_rows').val());
			jetFormData.append('jet_products_vr', $('#jet_products_vr').val());
			jetFormData.append('jet_vnoski', parseInt($('#jet_vnoski').val()));
			jetFormData.append('jet_products', $('#jet_products').val());
			jetFormData.append('jet_products_qt', $('#jet_products_qt').val());
			jetFormData.append('jet_products_pr', $('#jet_products_pr').val());
			const formGeneration = jetFormGeneration;
			const creatingIntent = !discovery && !jetIntentId;
			if (creatingIntent) jetIntentPending = true;
			try {
			$.ajax({
				url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_calculate_cart',
				method: 'POST',
				data: jetFormData,
				processData: false,
				contentType: false,
				timeout: 30000,
				success: function(json) {
					if (formGeneration !== jetFormGeneration) return;
					if (creatingIntent && json && typeof json.jet_intent_id === 'string' && /^[a-f0-9]{48}$/.test(json.jet_intent_id)) jetIntentId = json.jet_intent_id;
					if (revision !== jetCalculationRevision || state !== jetQuoteState()) return;
					if (json && json.jet_quote_invalid === true) { jetPreviousQuote = ''; jetCartLineage = ''; }
					if (json && json.jet_cart_stale === true) { jetOpenPending = false; jetShowCustomAlert(jetStaleMessage, false); return; }
					if (json && json.jet_submission_review === true) { jetOpenPending = false; jetSubmission.needsReview = true; jetShowCustomAlert(jetSubmissionUncertain, false); return; }
					if (discovery) {
						if (!json || json.success !== 'success' || !json.jet_cart_schemes) return;
						for (const [index, channel] of ['jet', 'jet_card'].entries()) {
							const scheme = json.jet_cart_schemes[channel];
							const suffix = index ? '_card' : '';
							const valid = scheme && Number(scheme.jet_card) === index && Number(scheme.jet_priceall) === Number(jetPriceall.toFixed(2))
								&& [3, 6, 9, 10, 12, 15, 18, 24, 30, 36].includes(Number(scheme.jet_vnoski))
								&& Number.isFinite(Number(scheme['jet_vnoska' + suffix])) && Number(scheme['jet_vnoska' + suffix]) >= 20;
							jetSetEntry(index, valid && (!index || Number($('#jet_card_in').val()) === 1) ? scheme : null);
						}
						if (jetInitialSchemes.some(Boolean)) $('#jet-product-button-container').show();
						else $('#jet-product-button-container').hide();
					return;
					}
					if (json && (json.jet_scheme_invalid === true || json.jet_promotion_excluded === true)) { jetSchemeRejected(); return; }
					if (json && json.success === 'success') {
						const suffix = card ? '_card' : '';
						const fields = ['vnoska', 'gpr', 'glp', 'obshto'];
						if (!(json.jet_intent_id === jetIntentId && jetIntentId && typeof json.jet_quote_ref === 'string' && /^[a-f0-9]{48}$/.test(json.jet_quote_ref)
							&& typeof json.jet_cart_lineage === 'string' && /^[a-f0-9]{48}$/.test(json.jet_cart_lineage) && json.jet_parva === canonicalDown
							&& json.jet_show_button === true && Number(json.jet_vnoski) === Number($('#jet_vnoski').val()) && Number(json.jet_card) === card
							&& Number(json.jet_priceall) === Number(jetPriceall.toFixed(2))
							&& fields.every(field => typeof json['jet_' + field + suffix] === 'string' && json['jet_' + field + suffix] !== '' && Number.isFinite(Number(json['jet_' + field + suffix]))))) {
							jetSchemeRejected(); return;
						}
						jetPreviousQuote = json.jet_quote_ref; jetCartLineage = json.jet_cart_lineage;
						jetActiveQuote = { intent: jetIntentId, reference: json.jet_quote_ref, state, downpayment: canonicalDown };
						jetSchemeStatus(false);
						jetSchemeNoticeState = null;
						for (const field of ['priceall', 'total_credit_price']) {
							$('#jet_' + field).text(json['jet_' + field]);
							$('#jet_' + field + '_second').text(json['jet_' + field + '_second']);
						}
						for (const field of fields) {
							const target = field === 'vnoska' ? 'vnoska_popup' : field;
							$('#jet_' + target).text(json['jet_' + field + suffix]);
							$('#jet_' + target + '_second').text(json['jet_' + field + suffix + '_second']);
						}
						if (jetOpenPending) $('#jet-product-popup-container').show();
					} else { jetSchemeRejected(); }

					jetOpenPending = false;
				},
				error: function(error) {
					if (revision === jetCalculationRevision) { jetOpenPending = false; console.error('Error:', error); }
				},
				complete: function() { if (formGeneration !== jetFormGeneration) return; jetFinishCalculation(revision); if (creatingIntent) { jetIntentPending = false; if (jetIntentQueued) { jetIntentQueued = false; jetCalculate(false); } } if (revision === jetCalculationRevision && jetQuoteCurrent()) { changeBtnJetcredit(); changeBtnJetcreditBuy(); } }
			});
			} catch (error) {
				jetOpenPending = false;
				if (creatingIntent) { jetIntentPending = false; jetIntentQueued = false; }
				jetFinishCalculation(revision);
			}
		} else {
			jetOpenPending = false;
			jetFinishCalculation(revision);
			$("#jet_alert_overlay").addClass("show");
			jetShowCustomAlert('Първоначалната вноска трябва да бъде по-малка от цената на стоките!', false);
		}
	}

	const jetClose = () => {
		if (jetSubmission.locked) return;
		jetOpenPending = false;
		clearTimeout(jetDownpaymentTimer);
		++jetCalculationRevision;
		jetPopupLoading(false);
		if (!jetSubmission.needsReview) { ++jetFormGeneration; jetIntentId = ''; jetPreviousQuote = ''; jetCartLineage = ''; jetInvalidateQuote(); }
		$('#jet-product-popup-container').hide();
		$('#jet_step_1').css('display', 'block');
		$('#jet_step_2').css('display', 'none');
		$('#uslovia').prop('checked', false);
		$('#uslovia1').prop('checked', false);
		$('#uslovia2').prop('checked', false);
		$('#jet_parva').val(0);
		jetSchemeStatus(false);
		changeBtnJetcredit();
	}

	const jetShowCustomAlert = (message, exit, information = false) => {
		if (information) {
			if (jetCloseSchemeDialog) jetCloseSchemeDialog(false);
			$('#jet_alert_box').remove();
		}
		$('#jet_alert_box:empty').remove();
		const jetAlertBox = $('<div></div>', {
			id: 'jet_alert_box',
			css: {
				position: 'fixed',
				top: '50%',
				left: '50%',
				transform: 'translate(-50%, -50%)',
				backgroundColor: '#fff',
				padding: '20px',
				borderRadius: '5px',
				boxShadow: '0 0 10px rgba(0, 0, 0, 0.1)',
				zIndex: '5000001',
				width: '300px',
				textAlign: 'center'
			}
		});
		const returnFocus = document.activeElement;
		if (information) {
			jetAlertBox.attr({ role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'jet_scheme_title',
				'aria-describedby': 'jet_scheme_message', 'data-jet-scheme-dialog': 'true' })
				.css({ width: '360px', maxWidth: 'calc(100vw - 32px)', maxHeight: 'calc(100vh - 32px)', overflowY: 'auto', boxSizing: 'border-box' });
			jetAlertBox.append($('<h3></h3>', { id: 'jet_scheme_title', text: 'Информация' }));
		}
		const jetMessageText = $('<p></p>', {
			text: message,
			css: {
				fontFamily: '"Roboto Condensed", sans-serif',
				color: '#14532d'
			}
		});
		if (information) jetMessageText.attr('id', 'jet_scheme_message').css('white-space', 'pre-line');
		jetAlertBox.append(jetMessageText);
		const jetCloseButton = $('<button></button>', {
			type: 'button',
			text: 'Добре',
			css: {
				fontFamily: '"Roboto Condensed", sans-serif',
				fontWeight: '500',
				marginTop: '20px',
				padding: '10px 20px',
				border: 'none',
				backgroundColor: '#166534',
				color: '#fff',
				borderRadius: '3px',
				cursor: 'pointer'
			}
		});
		jetCloseButton.on('click', function() {
			if (information) { jetCloseSchemeDialog(); return; }
			jetAlertBox.remove();
			if (jetSubmission.locked) jetUnlockSubmission();
			if (exit) {
				window.location.href = window.location.origin;
			}
			$('#jet_alert_overlay').removeClass('show');
		});
		jetAlertBox.append(jetCloseButton);
		$('body').append(jetAlertBox);
		if (information) {
			let overlay = $('#jet_alert_overlay');
			const createdOverlay = !overlay.length;
			if (createdOverlay) overlay = $('<div>', { id: 'jet_alert_overlay', class: 'jet_alert_overlay' }).appendTo('body');
			overlay.appendTo('body').addClass('show').css({ display: 'block', position: 'fixed', top: 0, left: 0,
				width: '100%', height: '100%', backgroundColor: 'rgba(0, 0, 0, 0.5)', zIndex: 5000000 });
			jetCloseSchemeDialog = (restoreFocus = true) => {
				$(document).off('.jetSchemeDialog');
				jetAlertBox.remove();
				overlay.removeClass('show').css('display', '');
				if (createdOverlay) overlay.remove();
				jetCloseSchemeDialog = null;
				if (restoreFocus) {
					const controls = document.getElementById('jet-product-popup-container');
					const target = returnFocus && returnFocus.isConnected !== false && controls && controls.contains(returnFocus)
						? returnFocus : document.getElementById('jet_vnoski');
					if (target) target.focus();
				}
			};
			jetAlertBox[0].jetCloseSchemeDialog = jetCloseSchemeDialog;
			$(document).off('.jetSchemeDialog').on('keydown.jetSchemeDialog', (event) => {
				if (event.key === 'Escape' || event.key === 'Tab') {
					event.preventDefault(); event.stopImmediatePropagation();
					if (event.key === 'Escape') jetCloseSchemeDialog(); else jetCloseButton[0].focus();
				}
			}).on('focusin.jetSchemeDialog', (event) => {
				if (!jetAlertBox[0].contains(event.target)) jetCloseButton[0].focus();
			});
			jetCloseButton[0].focus();
		}

		if (jetSubmission.locked) {
			jetSubmission.alert = jetAlertBox[0];
			jetSubmissionLoading(false);
			$('#jet_alert_overlay').text('').removeAttr('role').addClass('show');
			jetAlertBox.attr({ role: 'alertdialog', 'aria-modal': 'true' });
			jetCloseButton[0].focus();
		}
	}

	const jetSend = () => {
		if (jetSubmission.locked || jetSubmission.needsReview || !jetQuoteCurrent()) return;
		const acceptedQuote = jetActiveQuote;
		$('#jet_parva').val(acceptedQuote.downpayment);
		jetLockSubmission();
		let requestStarted = false;
		try {
		const jetFormSendData = new FormData();
		jetFormSendData.append('jet_quote_ref', acceptedQuote.reference);
		jetFormSendData.append('jet_intent_id', acceptedQuote.intent);
		jetFormSendData.append('jet_cart_rows', $('#jet_cart_rows').val());
		jetFormSendData.append('jet_downpayment_raw', acceptedQuote.downpayment);
		jetFormSendData.append('jet_priceall', $('#jet_priceall').text());
		jetFormSendData.append('jet_vnoski', $('#jet_vnoski').val());
		jetFormSendData.append('jet_vnoska', $('#jet_vnoska_popup').text());
		jetFormSendData.append('jet_parva', $('#jet_parva').val());
		jetFormSendData.append('jet_total_credit_price', $('#jet_total_credit_price').text());
		jetFormSendData.append('jet_obshto', $('#jet_obshto').text());
		jetFormSendData.append('jet_gpr', $('#jet_gpr').text());
		jetFormSendData.append('jet_glp', $('#jet_glp').text());
		jetFormSendData.append('jet_name', $('#jet_name').val());
		jetFormSendData.append('jet_lastname', $('#jet_lastname').val());
		jetFormSendData.append('jet_egn', $('#jet_egn').val());
		jetFormSendData.append('jet_email', $('#jet_email').val());
		jetFormSendData.append('jet_phone', $('#jet_phone').val());
		jetFormSendData.append('jet_lname', $('#jet_lname').val());
		jetFormSendData.append('jet_card', card);
		jetFormSendData.append('jet_products', $('#jet_products').val());
		jetFormSendData.append('jet_products_qt', $('#jet_products_qt').val());
		jetFormSendData.append('jet_products_pr', $('#jet_products_pr').val());
		jetFormSendData.append('jet_products_vr', $('#jet_products_vr').val());
		requestStarted = true;
		$.ajax({
			url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_send_cart',
			method: 'POST',
			data: jetFormSendData,
			processData: false,
			contentType: false,
			timeout: 30000,
			success: function(json) {
				if (!json || json.jet_submission_review === true || !['success', 'unsuccess'].includes(json.success) || json.code === 'bank_uncertain') {
					jetSubmission.needsReview = true;
					jetShowCustomAlert(jetSubmissionUncertain, false);
					return;
				}
				if (json.jet_quote_invalid === true && !json.order_id) { jetInvalidateQuote(); jetPreviousQuote = ''; jetCartLineage = ''; }
				if (json.jet_cart_stale === true && !json.order_id) { jetShowCustomAlert(jetStaleMessage, false); return; }
				if (json.success !== 'success' && json.order_id) jetSubmission.needsReview = true;
				if (json.success === 'success') {
					jetShowCustomAlert('Успешно изпратихте Вашата заявка за лизинг към ПБ Лични Финанси. Очаквайте контакт за потвърждаване на направената от Вас заявка.', true);
				} else {
					jetShowCustomAlert('Не можете да изпратите Вашата заявка за лизинг към ПБ Лични Финанси. Моля опитайте по-късно.', false);
				}
			},
			error: function(error) {
				console.error('Error:', error);
				jetSubmission.needsReview = true;
				jetShowCustomAlert(jetSubmissionUncertain, false);
			}
		});
		} catch (error) {
			jetSubmission.needsReview = requestStarted;
			jetShowCustomAlert(requestStarted ? jetSubmissionUncertain : 'Не можете да изпратите Вашата заявка за лизинг към ПБ Лични Финанси. Моля опитайте по-късно.', false);
		}
	}

	const jetHideOptions401 = () => {
		$('#jet_vnoski').find('option').each(function() {
			if ($(this).val() === '15' || $(this).val() === '18' || $(this).val() === '24') {
				$(this).prop('disabled', true);
			}
		});
	}

	const jetShowOptions401 = () => {
		$('#jet_vnoski').find('option').each(function() {
			if ($(this).val() === '15' || $(this).val() === '18' || $(this).val() === '24') {
				$(this).prop('disabled', false);
			}
		});
	}

	const jetHideOptions601 = () => {
		$('#jet_vnoski').find('option').each(function() {
			if ($(this).val() === '30' || $(this).val() === '36') {
				$(this).prop('disabled', true);
			}
		});
	}

	const jetShowOptions601 = () => {
		$('#jet_vnoski').find('option').each(function() {
			if ($(this).val() === '30' || $(this).val() === '36') {
				$(this).prop('disabled', false);
			}
		});
	}

	const changeBtnJetcredit = () => {
		if (!jetQuoteCurrent()) { $('#buy_jetcredit').prop('disabled', true); return; }
		if ($('#uslovia').is(':checked') && $('#uslovia1').is(':checked')) {
			let _taxa = parseFloat($('#jet_vnoska_popup').text());
			if (_taxa >= 20 && jetQuoteCurrent()) {
				$('#buy_jetcredit').prop('disabled', false).css({
					opacity: 1.00
				});
			} else {
				$("#jet_alert_overlay").addClass("show");
				jetShowCustomAlert('Месечната вноска трябва да надхвърля сумата от 20 евро!', false);
				$('#uslovia').prop('checked', false);
				$('#uslovia1').prop('checked', false);
			}
		} else {
			$('#buy_jetcredit').prop('disabled', true).css({
				opacity: 0.50
			});
		}
	}

	const checkForm = () => {
		let check = true;
		const jet_name = document.getElementById('jet_name').value.trim();
		if (jet_name === '') {
			document.getElementById('jet_name').classList.add('error');
			check = false;
		}
		const jet_lastname = document.getElementById('jet_lastname').value.trim();
		if (jet_lastname === '') {
			document.getElementById('jet_lastname').classList.add('error');
			check = false;
		}
		const egnRe = /^[0-9]{2}((0[1-9]|1[0-2])|(4[1-9]|5[0-2]))(0[0-9]|1[0-9]|2[0-9]|3[0-1])[0-9]{4}$/;
		const jet_egn = document.getElementById('jet_egn').value.trim();
		if (jet_egn === '' || !egnRe.test(jet_egn)) {
			document.getElementById('jet_egn').classList.add('error');
			check = false;
		}
		const phoneRe = /^[+0-9]+$/;
		const jet_phone = document.getElementById('jet_phone').value.trim();
		if (jet_phone === '' || jet_phone.length < 10 || !phoneRe.test(jet_phone)) {
			document.getElementById('jet_phone').classList.add('error');
			check = false;
		}
		const re = /^[a-zA-Z0-9.!#$%&'*+/=?^_'{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/;
		const jet_email = document.getElementById('jet_email').value.trim().toLowerCase();
		const position = jet_email.indexOf('@') + 1;
		const ostatak = jet_email.substr(position);
		if (jet_email === '' || !re.test(jet_email) || ostatak.indexOf('.') === -1) {
			document.getElementById('jet_email').classList.add('error');
			check = false;
		}
		return check;
	}

	const jetDebounce = (func, delay) => {
		let inDebounce;
		return function() {
			const context = this;
			const args = arguments;
			clearTimeout(inDebounce);
			inDebounce = setTimeout(() => func.apply(context, args), delay);
		}
	}

	const changeBtnJetcreditBuy = () => {
		if (jetSubmission.locked || jetSubmission.needsReview || !jetQuoteCurrent()) {
			$('#buy2_jetcredit').prop('disabled', true);
			return;
		}
		if ($('#uslovia2').is(':checked')) {
			$('#buy2_jetcredit').prop('disabled', false).css({
				opacity: 1.00
			});
		} else {
			$('#buy2_jetcredit').prop('disabled', true).css({
				opacity: 0.50
			});
		}
	}

	/* body */
	document.addEventListener('click', (event) => {
		if (event.target.id == "uslovia" || event.target.id == "uslovia1") {
			changeBtnJetcredit();
		}
		if (event.target.id == "uslovia2") {
			changeBtnJetcreditBuy();
		}
		if (event.target.id == "btn_preizcisli") {
			jetCalculate();
		}
	});

	document.addEventListener('change', (event) => {
		if (event.target.id == "jet_vnoski") {
			jetCalculate();
		}
	});

	$('#jet_parva').off('.jetRecalculation').on('input.jetRecalculation change.jetRecalculation', jetScheduleDownpayment);
	$('#jet_parva').on('blur.jetRecalculation', () => {
		if (String($('#jet_parva').val() || '').trim() === '') $('#jet_parva').val(0);
	});

	jetLoad();
});
