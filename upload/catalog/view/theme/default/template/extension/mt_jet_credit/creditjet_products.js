jQuery(document).ready(function($) {
	let card;
	card = 0;
	if ($("#jet-product-popup-container").length) {
		$("#jet-product-popup-container").prependTo("body");
	}


	let jetCalculationRevision = 0;
	let jetDownpaymentTimer;
	const jetDownpaymentValue = () => {
		const value = String($('#jet_parva').val() || '').trim();
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

	const jetChange = (jetPriceall, revision) => {
		if (revision !== jetCalculationRevision) return;
		jetHideOptions401();
		jetHideOptions601();
		if (jetPriceall >= 401) {
			jetShowOptions401();
		}
		if (jetPriceall >= 601) {
			jetShowOptions601();
		}
		if ($('#jet_parva').length && $('#jet_parva').val().trim() == '' || parseFloat($('#jet_parva').val()) < jetPriceall) {
			const jetFormData = new FormData();
			jetFormData.append('jet_priceall', jetPriceall.toFixed(2));
			jetFormData.append('jet_parva', parseFloat($('#jet_parva').val()).toFixed(2));
			jetFormData.append('jet_vnoski', parseInt($('#jet_vnoski').val()));
			jetFormData.append('jet_product_id', parseInt($('#jet_product_id').val()));
			$.ajax({
				url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_calculate',
				method: 'POST',
				data: jetFormData,
				processData: false,
				contentType: false,
				timeout: 30000,
				success: function(json) {
					if (revision !== jetCalculationRevision) return;
					if (json.success === 'success') {
						if (json.jet_show_button) {
							$('#jet-product-button-container').show('slow');
						}else{
							$('#jet-product-button-container').hide('slow');
						}
						$('#jet_vnoska').text(json.jet_vnoska);
						$('#jet_vnoska_second').text(json.jet_vnoska_second);
						$('#jet_vnoski_text').text(parseInt($('#jet_vnoski').val()));
						$('#jet_priceall').text(json.jet_priceall);
						$('#jet_priceall_second').text(json.jet_priceall_second);
						$('#jet_total_credit_price').text(json.jet_total_credit_price);
						$('#jet_total_credit_price_second').text(json.jet_total_credit_price_second);
						$('#jet_vnoska_popup').text(json.jet_vnoska);
						$('#jet_vnoska_popup_second').text(json.jet_vnoska_second);
						$('#jet_gpr').text(json.jet_gpr);
						$('#jet_glp').text(json.jet_glp);
						$('#jet_obshto').text(json.jet_obshto);
						$('#jet_obshto_second').text(json.jet_obshto_second);
						
						if (parseInt($('#jet_card_in').val()) === 1) {
							$('#jet_vnoska_card').text(json.jet_vnoska_card);
							$('#jet_vnoska_card_second').text(json.jet_vnoska_card_second);
							$('#jet_vnoski_text_card').text(parseInt($('#jet_vnoski').val()));
							
							if (card === 1) {
								$('#jet_vnoska_popup').text(json.jet_vnoska_card);
								$('#jet_vnoska_popup_second').text(json.jet_vnoska_card_second);
								$('#jet_gpr').text(json.jet_gpr_card);
								$('#jet_glp').text(json.jet_glp_card);
								$('#jet_obshto').text(json.jet_obshto_card);
								$('#jet_obshto_second').text(json.jet_obshto_card_second);
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
			if ($('#jet_parva').length) {
				$('#jet_alert_overlay').addClass('show');
				jetShowCustomAlert('Първоначалната вноска трябва да бъде по-малка от цената на стоките!', false);
			}
		}
	}

	const jetCalculate = (test) => {
		const revision = jetBeginCalculation();
		let jet_price1 = $('#jet_price').val();
		let jet_quantity = 1;
		if ($('input[name="quantity"]').length) {
			jet_quantity = parseInt($('input[name="quantity"]').val());
		}
		let jetPriceall = parseFloat(jet_price1) * jet_quantity;
		let total_price = 0;
		let jet_variation_id = [];
		if (document.querySelectorAll("[id*='input-option']").length > 0 ) {
			let calculationStarted = false;
			$.ajax({
				url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_check',
				type: 'post',
				data: $('#product input[type=\'text\'], #product input[type=\'hidden\'], #product input[type=\'radio\']:checked, #product input[type=\'checkbox\']:checked, #product select, #product textarea'),
				dataType: 'json',
				timeout: 30000,
				success: function(json) {
					if (revision !== jetCalculationRevision) return;
					$('.alert, .text-danger').remove();
					if (json['error']) {
						calculationStarted = true;
						jetChange(jetPriceall, revision);
						if (test) {
							if (json['error']['option']) {
								for (i in json['error']['option']) {
									var element = $('#input-option' + i.replace('_', '-'));
									if (element.parent().hasClass('input-group')) {
										element.parent().after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
									} else {
										element.after('<div class="text-danger">' + json['error']['option'][i] + '</div>');
									}
								}
							}
							$('.text-danger').parent().addClass('has-error');
							$('#jet_alert_overlay').addClass("show");
							jetShowCustomAlert('Моля изберете стойност за задължителните опции за продукта!', true);
						}
					}else{
						if (json['success']) {
							for (i = 0; i < json['optionresult'].length; i++) { 
								var current_options = json['optionresult'][i]['product_option_id_check'];
								if( Object.prototype.toString.call( current_options ) === '[object Array]' ) {
									for (m = 0; m < current_options.length; m++){
										for (n = 0; n < json['optionresult'][i]['product_option_value'].length; n++){
											var tempid = parseInt(JSON.stringify(json['optionresult'][i]['product_option_value'][n]['product_option_value_id']).replace(/['"]+/g, ''));
											var curid = parseInt(JSON.stringify(current_options[m]).replace(/['"]+/g, ''));
											if (tempid == curid) {
												const product_option_id_check = json['optionresult'][i]['product_option_id_check'];
												const option_id = product_option_id_check.indexOf(json['optionresult'][i]['product_option_value'][n]['product_option_value_id']);
												jet_variation_id = [...jet_variation_id, {
													'product_option_id' : json['optionresult'][i]['product_option_id'],
													'product_option_value_id' : json['optionresult'][i]['product_option_value'][n]['product_option_value_id'],
													'option_id' : option_id,
													'option_value_id' : json['optionresult'][i]['product_option_value'][n]['option_value_id'],
													'name' : json['optionresult'][i]['name'],
													'value' : json['optionresult'][i]['product_option_value'][n]['name'],
													'type' : json['optionresult'][i]['type']
												}];
												total_price += parseFloat(JSON.stringify(json['optionresult'][i]['product_option_value'][n]['price']).replace(/['"]+/g, ''));
											}
										}
									}
								}else{
									for (j = 0; j < json['optionresult'][i]['product_option_value'].length; j++){
										var tempid = parseInt(JSON.stringify(json['optionresult'][i]['product_option_value'][j]['product_option_value_id']).replace(/['"]+/g, ''));
										var curid = parseInt(JSON.stringify(current_options).replace(/['"]+/g, ''));
										if (tempid == curid){
											const product_option_id_check = json['optionresult'][i]['product_option_id_check'];
											const option_id = product_option_id_check.indexOf(json['optionresult'][i]['product_option_value'][j]['product_option_value_id']);
											jet_variation_id = [...jet_variation_id, {
												'product_option_id' : json['optionresult'][i]['product_option_id'],
												'product_option_value_id' : json['optionresult'][i]['product_option_value'][j]['product_option_value_id'],
												'option_id' : option_id,
												'option_value_id' : json['optionresult'][i]['product_option_value'][j]['option_value_id'],
												'name' : json['optionresult'][i]['name'],
												'value' : json['optionresult'][i]['product_option_value'][j]['name'],
												'type' : json['optionresult'][i]['type']
											}];
											total_price += parseFloat(JSON.stringify(json['optionresult'][i]['product_option_value'][j]['price']).replace(/['"]+/g, ''));
										}
									}
								}
							}
							jet_price1 = parseFloat(jet_price1) + total_price;
							jetPriceall = parseFloat(jet_price1) * jet_quantity;
							calculationStarted = true;
							jetChange(jetPriceall, revision);
							$('#jet_variation_id').val(JSON.stringify(jet_variation_id));
						}
					}
				},
				error: function(error) {
					if (revision === jetCalculationRevision) console.error('Error:', error);
				},
				complete: function() {
					if (!calculationStarted) jetFinishCalculation(revision);
				}
			});
		} else {
			jetChange(jetPriceall, revision);
			$('#jet_variation_id').val(JSON.stringify(jet_variation_id));
		}
	}

	const jetClose = () => {
		$('#jet-product-popup-container').hide();
		$('#jet_step_1').show();
		$('#jet_step_2').hide();
		$('#uslovia').prop('checked', false);
		$('#uslovia1').prop('checked', false);
		$('#uslovia2').prop('checked', false);
		const jet_parva = $('#jet_parva');
		if (parseFloat(jet_parva.val()) !== 0) {
			jet_parva.val(0);
			jetCalculate(false);
		}
		changeBtnJetcredit();
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
				$('#buy_jetcredit').prop('disabled', false);
				$('#buy_jetcredit').css({
					opacity: 1.00
				});
			} else {
				$('#jet_alert_overlay').addClass('show');
				jetShowCustomAlert('Месечната вноска трябва да надхвърля сумата от 20 евро!', false);
				$('#uslovia').prop('checked', false);
				$('#uslovia1').prop('checked', false);
			}
		} else {
			$('#buy_jetcredit').prop('disabled', true);
			$('#buy_jetcredit').css({
				opacity: 0.50
			});
		}
	}

	const changeBtnJetcreditBuy = () => {
		if ($('#uslovia2').is(':checked')) {
			$('#buy2_jetcredit').prop('disabled', false);
			$('#buy2_jetcredit').css({
				opacity: 1.00
			});
		} else {
			$('#buy2_jetcredit').prop('disabled', true);
			$('#buy2_jetcredit').css({
				opacity: 0.50
			});
		}
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
				jetClose();
			}
			$('#jet_alert_overlay').removeClass('show');
		});
		jetAlertBox.append(jetCloseButton);
		$('body').append(jetAlertBox);
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

	const jetSend = () => {
		$('#jet_alert_overlay').addClass('show');
		let jet_quantity = 1;
		if ($('input[name="quantity"]').length !== 0) {
			jet_quantity = parseInt($('input[name="quantity"]').first().val());
		}
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
		jetFormSendData.append('jet_product_id', $('#jet_product_id').val());
		jetFormSendData.append('jet_variation_id', $('#jet_variation_id').val());
		jetFormSendData.append('jet_quantity', jet_quantity);
		$.ajax({
			url: 'index.php?route=extension/mt_jet_credit/jetcredit/jet_send',
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

	$('#jet_step_1').show();
	$('#jet_step_2').hide();

	$('#btn_jet').on('click', event => {
		card = 0;
		jetCalculate(true);
		$('#jet-product-popup-container').show();
	});

	if ($('#btn_jet_card').length) {
		$('#btn_jet_card').on('click', event => {
			card = 1;
			jetCalculate(true);
			$('#jet-product-popup-container').show();
		});
	}

	$('#btn_preizcisli').on('click', event => {
		jetCalculate(false);
	});

	$('#jet_vnoski').on('change', event => {
		jetCalculate(false);
	});

	$('#back_jetcredit').on('click', event => {
		jetClose();
	});

	$('#close_jetcredit').on('click', event => {
		jetClose();
	});

	$('#buy_jetcredit').on('click', event => {
		let _taxa = parseFloat($('#jet_vnoska_popup').text());
		if (_taxa >= 20) {
			$('#jet_step_1').hide('slow');
			$('#jet_step_2').show('slow');
		}else{
			$('#jet_alert_overlay').addClass("show");
			jetShowCustomAlert('Месечната вноска трябва да надхвърля сумата от 20 евро!', false);
		}
	});

	$('#buy_cart_jetcredit').on('click', event => {
		const jet_buy_buttons_submit = $('#button-cart');
		if (jet_buy_buttons_submit.length) {
			jet_buy_buttons_submit.click();
		}
		jetClose();
	});

	$('#back2_jetcredit').on('click', event => {
		$('#jet_step_2').hide('slow');
		$('#jet_step_1').show('slow');
	});

	$('#jet_egn').on('input', event => {
		const input = event.target.value;
		const regex = /^[0-9]{0,10}$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});

	$('#jet_phone').on('input', event => {
		const input = event.target.value;
		const regex = /^[+0-9]+$/;
		if (!regex.test(input)) {
			event.target.value = input.slice(0, -1);
		}
	});

	$('#buy2_jetcredit').on('click', event => {
		if (checkForm()) {
			jetSend();
		}
	});

	$('.jet_input_text_active').on('click', (event) => {
		if (event.target.classList.contains('error')) {
			event.target.classList.remove('error');
		}
	});

	$('#uslovia').on('click', () => {
		changeBtnJetcredit();
	});

	$('#uslovia1').on('click', () => {
		changeBtnJetcredit();
	});

	$('#uslovia2').on('click', () => {
		changeBtnJetcreditBuy();
	});

	if ($('[name="quantity"]').length) {
		$('[name="quantity"]').eq(0).on('change', () => {
			jetCalculate(false);
		});
	}

	const targetNodes = document.querySelectorAll('[id^="input-option"]');
	if (targetNodes.length > 0) {
		targetNodes.forEach((targetNode) => {
			targetNode.addEventListener('change', function() {
				jetCalculate(false);
			});
		});
	}

	$('#jet_parva').off('.jetRecalculation').on('input.jetRecalculation change.jetRecalculation', jetScheduleDownpayment);

	jetCalculate(false);
});