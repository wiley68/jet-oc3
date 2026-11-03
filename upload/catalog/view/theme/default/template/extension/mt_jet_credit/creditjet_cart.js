jQuery(document).ready(function($) {
	let card;
	card = 0;

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
		++jetCalculationRevision;
		clearTimeout(jetDownpaymentTimer);
		jetDownpaymentTimer = setTimeout(() => jetCalculate(false), 400);
	};

	/* function */
	const jetLoad = () => {
		if ($('#btn_jet').length !== 0) {
			$('#jet_step_1').css('display', 'block');
			$('#jet_step_2').css('display', 'none');
			$('#btn_jet').on('click', function(event) {
				card = 0;
				jetCalculate();
				$('#jet-product-popup-container').css('display', 'block');
			});
			if ($('#btn_jet_card').length !== 0) {
				$('#btn_jet_card').on('click', function(event) {
					card = 1;
					jetCalculate();
					$('#jet-product-popup-container').css('display', 'block');
				});
			}
			$('#back_jetcredit').on('click', function(event) {
				jetClose();
			});
			$('#close_jetcredit').on('click', function(event) {
				jetClose();
			});
			$('#buy_jetcredit').on('click', function(event) {
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
				if (checkForm()) {
					jetSend();
				}
			});
			$('.jet_input_text_active').on('click', function(event) {
				if ($(this).hasClass('error')) {
					$(this).removeClass('error');
				}
			});
			jetCalculate();
		}
	}

	const jetCalculate = () => {
		const revision = jetBeginCalculation();
		const jetPriceall = parseFloat($('#jet_price').val());
		const jetDownpayment = parseFloat(jetDownpaymentValue());
		jetHideOptions401();
		jetHideOptions601();
		if (jetPriceall >= 401) {
			jetShowOptions401();
		}
		if (jetPriceall >= 601) {
			jetShowOptions601();
		}
		if (jetDownpayment < jetPriceall) {
			const jetFormData = new FormData();
			jetFormData.append('jet_priceall', jetPriceall.toFixed(2));
			jetFormData.append('jet_parva', jetDownpayment.toFixed(2));
			jetFormData.append('jet_vnoski', parseInt($('#jet_vnoski').val()));
			jetFormData.append('jet_products', $('#jet_products').val());
			jetFormData.append('jet_products_qt', $('#jet_products_qt').val());
			jetFormData.append('jet_products_pr', $('#jet_products_pr').val());
			$.ajax({
				url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_calculate_cart',
				method: 'POST',
				data: jetFormData,
				processData: false,
				contentType: false,
				timeout: 30000,
				success: function(json) {
					if (revision !== jetCalculationRevision) return;
					if (json.success === 'success') {
						if ($('#jet_vnoska').length !== 0) $('#jet_vnoska').text(json.jet_vnoska);
						if ($('#jet_vnoska_second').length !== 0) $('#jet_vnoska_second').text(json.jet_vnoska_second);
						$('#jet_vnoski_text').text(parseInt($('#jet_vnoski').val()));
						$('#jet_priceall').text(json.jet_priceall);
						if ($('#jet_priceall_second').length !== 0) $('#jet_priceall_second').text(json.jet_priceall_second);
						$('#jet_total_credit_price').text(json.jet_total_credit_price);
						if ($('#jet_total_credit_price_second').length !== 0) $('#jet_total_credit_price_second').text(json.jet_total_credit_price_second);
						$('#jet_vnoska_popup').text(json.jet_vnoska);
						if ($('#jet_vnoska_popup_second').length !== 0) $('#jet_vnoska_popup_second').text(json.jet_vnoska_second);
						$('#jet_gpr').text(json.jet_gpr);
						$('#jet_glp').text(json.jet_glp);
						$('#jet_obshto').text(json.jet_obshto);
						if ($('#jet_obshto_second').length !== 0) $('#jet_obshto_second').text(json.jet_obshto_second);
						if (parseInt($('#jet_card_in').val()) == 1) {
							if ($('#jet_vnoska_card').length !== 0) {
								$('#jet_vnoska_card').text(json.jet_vnoska_card);
								if ($('#jet_vnoska_card_second').length !== 0) $('#jet_vnoska_card_second').text(json.jet_vnoska_card_second);
								$('#jet_vnoski_text_card').text(parseInt($('#jet_vnoski').val()));
								if (card == 1) {
									$('#jet_vnoska_popup').text(json.jet_vnoska_card);
									if ($('#jet_vnoska_popup_second').length !== 0) $('#jet_vnoska_popup_second').text(json.jet_vnoska_card_second);
									$('#jet_gpr').text(json.jet_gpr_card);
									$('#jet_glp').text(json.jet_glp_card);
									$('#jet_obshto').text(json.jet_obshto_card);
									if ($('#jet_obshto_second').length !== 0) $('#jet_obshto_second').text(json.jet_obshto_card_second);
								}
							}
						}
					}
				},
				error: function(error) {
					if (revision === jetCalculationRevision) console.error('Error:', error);
				},
				complete: function() { jetFinishCalculation(revision); }
			});
		} else {
			jetFinishCalculation(revision);
			$("#jet_alert_overlay").addClass("show");
			jetShowCustomAlert('Първоначалната вноска трябва да бъде по-малка от цената на стоките!', false);
		}
	}

	const jetClose = () => {
		$('#jet-product-popup-container').css('display', 'none');
		$('#jet_step_1').css('display', 'block');
		$('#jet_step_2').css('display', 'none');
		$('#uslovia').prop('checked', false);
		$('#uslovia1').prop('checked', false);
		$('#uslovia2').prop('checked', false);
		const $jet_parva = $('#jet_parva');
		if (parseFloat($('#jet_parva').val()) !== 0) {
			$('#jet_parva').val(0);
			jetCalculate();
		}
		changeBtnJetcredit();
	}

	const jetShowCustomAlert = (message, exit) => {
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
		const jetMessageText = $('<p></p>', {
			text: message,
			css: {
				fontFamily: '"Roboto Condensed", sans-serif',
				color: '#14532d'
			}
		});
		jetAlertBox.append(jetMessageText);
		const jetCloseButton = $('<button></button>', {
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
			jetAlertBox.remove();
			if (exit) {
				window.location.href = window.location.origin;
			}
			$('#jet_alert_overlay').removeClass('show');
		});
		jetAlertBox.append(jetCloseButton);
		$('body').append(jetAlertBox);
	}

	const jetSend = () => {
		$("#jet_alert_overlay").addClass("show");
		const jetFormSendData = new FormData();
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
		$.ajax({
			url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_send_cart',
			method: 'POST',
			data: jetFormSendData,
			processData: false,
			contentType: false,
			success: function(json) {
				if (json.success === 'success') {
					jetShowCustomAlert('Успешно изпратихте Вашата заявка за лизинг към ПБ Лични Финанси. Очаквайте контакт за потвърждаване на направената от Вас заявка.', true);
				} else {
					jetShowCustomAlert('Не можете да изпратите Вашата заявка за лизинг към ПБ Лични Финанси. Моля опитайте по-късно.', false);
				}
			},
			error: function(error) {
				console.error('Error:', error);
			}
		});
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
		if ($('#uslovia').is(':checked') && $('#uslovia1').is(':checked')) {
			let _taxa = parseFloat($('#jet_vnoska_popup').text());
			if (_taxa >= 20) {
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