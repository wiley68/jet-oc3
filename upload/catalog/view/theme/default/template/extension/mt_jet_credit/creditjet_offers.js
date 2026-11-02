(function (window, $) {
	'use strict';
	if (window.JetCreditOffers) return;
	let active = null;
	const messages = {
		non_eur_currency: 'Кредитното финансиране е достъпно само в евро.',
		invalid_eur_base: 'Кредитното финансиране временно не е достъпно.',
		required_options: 'Моля, изберете задължителните опции на продукта.',
		invalid_options: 'Проверете избраните опции на продукта.',
		invalid_quantity: 'Проверете количеството на продукта.',
		out_of_stock: 'Избраното количество не е налично.',
		principal_below_minimum: 'Финансираната главница е под допустимия минимум в евро.',
		monthly_below_minimum: 'Показаната месечна вноска трябва да е поне 10 евро.',
		invalid_term: 'Изберете допустим срок за тази покупка.',
		invalid_down_payment: 'Първоначалната вноска трябва да е неотрицателна и по-малка от цената.',
		invalid_money: 'Въведете сума в евро с най-много два знака след десетичната точка.',
		stale_order: 'Поръчката е променена. Обновете checkout и изчислете отново.',
		offer_expired: 'Офертата е изтекла. Изчислете отново.',
		stale_offer: 'Офертата е променена или изтекла. Преизчислете.',
		invalid_token: 'Обновете страницата и опитайте отново.',
		invalid_recipient_configuration: 'Кандидатстването временно не е достъпно. Свържете се с магазина.',
		consent_required: 'Потвърдете задължителните съгласия.',
		invalid_customer_input: 'Проверете личните данни и контактите.',
		review_required: 'Заявката изисква проверка. Не изпращайте нова заявка; проверете съществуващия резултат или се свържете с магазина.',
		manual_registered: 'Заявката е регистрирана в магазина и НЕ е изпратена до банката.',
		transport_accepted: 'Пощенският транспорт прие заявката. Това не е банково одобрение.',
		submission_unavailable: 'Кандидатстването временно не е достъпно. Не е изпратена заявка.'
	};
	function mount() {
		if (active) active.destroy();
		const field = $('#jet_finance_context');
		if (!field.length) return null;
		const context = field.attr('data-context');
		let scheme = field.attr('data-scheme') || 'jet';
		const prefix = context === 'checkout' && scheme === 'jet_card' ? '#jet_card_' : '#jet_';
		let alive = true, quoteLoading = false, editing = false, debounce = null;
		let lastQuoteKey = null, inFlightKey = null, quoteUsable = false;
		let application = field.attr('data-intent') || '', transition = '', completedIntent = '';
		let submissionRevision = 0;
		let sequence = 0, request = null, expiry = null, state = {offer: '', intent: '', eligible: false, enabled: false}, busy = false, pending = null;
		function loading(value) {
			quoteLoading = value;
			const popup = $('#jet-product-popup-container');
			if (value || busy) popup.addClass('jet_offer_loading'); else popup.removeClass('jet_offer_loading');
			popup.attr('aria-busy', value || busy ? 'true' : 'false');
		}
		function ready() {
			return alive && window.location.protocol === 'https:' && !busy && !quoteLoading && !editing && state.enabled && state.eligible && !!state.offer && !!state.intent && quoteUsable && lastQuoteKey === quoteKey(quoteData(false));
		}
		function consentReady() {
			return context === 'checkout' ? $(prefix + 'uslovia').prop('checked') && $(prefix + 'uslovia1').prop('checked') : $('#uslovia').prop('checked') && $('#uslovia1').prop('checked');
		}
		function personalReady() {
			if (context === 'checkout') return /^[0-9]{1,10}$/.test(String($(prefix + 'egn').val() || ''));
			return consentReady() && $('#uslovia2').prop('checked') &&
				['#jet_name','#jet_lastname','#jet_phone'].every(selector => String($(selector).val() || '').trim() !== '') &&
				/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String($('#jet_email').val() || '')) &&
				/^[0-9]{2}((0[1-9]|1[0-2])|(4[1-9]|5[0-2]))(0[0-9]|1[0-9]|2[0-9]|3[0-1])[0-9]{4}$/.test(String($('#jet_egn').val() || ''));
		}
		function canSubmit() { return alive && window.location.protocol === 'https:' && !busy && (pending || (ready() && consentReady() && personalReady())); }
		function unavailable() {
			$('#buy_jetcredit').prop('disabled', !(ready() && consentReady())).css('opacity', ready() && consentReady() ? 1 : 0.5);
			$('#buy2_jetcredit').prop('disabled', !canSubmit()).css('opacity', canSubmit() ? 1 : 0.5);
			$('#button-confirm').prop('disabled', !canSubmit());
		}
		function status(text) { $('#jet_finance_notice').text(text); }
		function notice(code) {
			status(typeof code === 'string' && Object.prototype.hasOwnProperty.call(messages, code) ? messages[code] : 'Неуспешно изчисление. Проверете данните и опитайте отново.');
		}
		function invalidate(preserveVisual) {
			quoteUsable = false;
			state = {offer: '', intent: '', eligible: false, enabled: false};
			field.val('');
			if (!preserveVisual) {
				show({amount: '', principal: '', monthly: '', gpr: '', glp: '', total: '', term: ''});
				$('#jet_vnoska_card').text('');
				$('#jet-product-button-container .jet_button_installment').hide();
			}
			if (expiry) clearTimeout(expiry);
			field.attr('data-quote-state', 'stale');
			unavailable();
		}
		function selection() {
			const options = {};
			document.querySelectorAll('#product [name^="option["]').forEach(function (node) {
				const match = /^option\[([0-9]+)\](\[\])?$/.exec(node.name);
				if (!match || node.disabled || ((node.type === 'checkbox' || node.type === 'radio') && !node.checked)) return;
				if (match[2]) { (options[match[1]] || (options[match[1]] = [])).push(node.value); }
				else options[match[1]] = node.value;
			});
			return options;
		}
		function show(calculation) {
			const fields = {priceall: 'amount', total_credit_price: 'principal', vnoska: 'monthly', vnoska_popup: 'monthly', gpr: 'gpr', glp: 'glp', obshto: 'total', vnoski_text: 'term'};
			Object.keys(fields).forEach(function (key) { $(prefix + key).text(calculation[fields[key]]); });
		}
		function cancelEditTimer() {
			if (debounce !== null) { clearTimeout(debounce); debounce = null; }
		}
		function downPayment(normalize) {
			const value = String($(prefix + 'parva').val() || '').trim();
			if (value !== '') return value;
			if (normalize) $(prefix + 'parva').val('0');
			return '0';
		}
		function quoteData(initial) {
			const data = {jet_intent: application, jet_context: context, jet_card: scheme === 'jet_card' ? '1' : '0', jet_csrf: field.attr('data-csrf'), jet_parva: downPayment(false), jet_vnoski: initial ? '' : $(prefix + 'vnoski').val()};
			if (context === 'product') {
				data.jet_product_id = field.attr('data-product-id');
				data.jet_quantity = $('#product input[name="quantity"]').val() || $('input[name="quantity"]').first().val() || '1';
				data.option = selection();
			}
			return data;
		}
		function quoteKey(data) {
			const copy = Object.assign({}, data), down = copy.jet_parva;
			// Canonicalization only for duplicate detection; server receives the entered amount.
			if (down.length <= 40 && /^\+?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/.test(down) && Number.isFinite(Number(down))) copy.jet_parva = String(Number(down));
			return JSON.stringify(copy);
		}
		function scheduleDownPayment() {
			if (!alive || busy || pending) return;
			if (quoteLoading && inFlightKey === quoteKey(quoteData(false))) return;
			cancelEditTimer();
			++sequence;
			if (request && request.abort) request.abort();
			request = null; inFlightKey = null;
			editing = true;
			loading(false);
			field.val('').attr('data-quote-state', 'updating');
			status('Офертата се обновява. Показани са последните изчислени стойности.');
			unavailable();
			debounce = setTimeout(function () { debounce = null; flushDownPayment(); }, 400);
		}
		function flushDownPayment() {
			cancelEditTimer();
			if (!alive || busy || pending) return;
			downPayment(true);
			const key = quoteKey(quoteData(false));
			if (quoteLoading && key === inFlightKey) return;
			if (quoteUsable && key === lastQuoteKey) {
				editing = false;
				field.val(state.offer).attr('data-quote-state', 'current');
				if (state.eligible) status('Офертата е готова.'); else notice('monthly_below_minimum');
				unavailable();
				return;
			}
			refresh(false, true);
		}
		function refresh(initial, preserveVisual) {
			if (!alive || busy || pending) return;
			cancelEditTimer();
			downPayment(true);
			editing = false;
			const current = ++sequence;
			if (request && request.abort) request.abort();
			invalidate(preserveVisual);
			loading(true);
			field.attr('data-quote-state', 'loading');
			unavailable();
			const data = quoteData(initial);
			inFlightKey = quoteKey(data);
			status('Изчисляване на офертата…');
			request = $.ajax({
				url: 'index.php?route=extension/mt_jet_credit/jetcredit/' + (context === 'product' ? 'jet_calculate' : 'jet_calculate_cart'),
				type: 'POST', dataType: 'json', timeout: 30000, data: data,
				success: function (json) {
					if (!alive || current !== sequence) return;
					loading(false);
					request = null; inFlightKey = null;
					field.attr('data-quote-state', 'stale');
					if (json && json.success === 'success' && json.application_intent !== application) { notice('stale_offer'); unavailable(); return; }
					if (json && json.success === 'success' && (json.existing_outcome === 'manual_registered' || json.existing_outcome === 'transport_accepted')) {
						completedIntent = application; notice(json.existing_outcome); $('#jet-product-button-container').show();
						$('#jet_new_purchase').remove(); $('#jet_previous_result').remove();
						$('#jet_finance_notice').after($('<button></button>').attr('id','jet_new_purchase').attr('type','button').text('Започни нова покупка на изплащане'));
						try { const target = new URL(json.redirect,window.location.href); if (target.origin===window.location.origin && target.searchParams.get('route')==='extension/mt_jet_credit/success') $('#jet_new_purchase').after($('<a></a>').attr('id','jet_previous_result').attr('href',target.href).text('Виж предходната заявка')); } catch (ignored) {}
						return;
					}
					if (!json || json.success !== 'success' || json.currency !== 'EUR' || json.context !== context || json.scheme !== scheme || !json.calculation || !Array.isArray(json.allowed_terms)) { notice(json && json.code); unavailable(); return; }
					const select = $(prefix + 'vnoski');
					select.empty();
					json.allowed_terms.forEach(function (term) {
						if (Number.isInteger(term) && term > 0) select.append($('<option></option>').val(String(term)).text(term + ' месеца'));
					});
					select.val(String(json.calculation.term));
					show(json.calculation);
					if (context !== 'checkout') {
						$('#jet_vnoska').text(json.previews && json.previews.jet ? json.previews.jet.monthly : '');
						$('#jet_vnoska_card').text(json.previews && json.previews.jet_card ? json.previews.jet_card.monthly : '');
						$('#jet_vnoski_text_card').text(json.calculation.term);
						$('#jet-product-button-container').show();
						['jet', 'jet_card'].forEach(function (previewScheme) {
							const preview = json.previews && json.previews[previewScheme];
							$('#jet-product-button-container [data-jet-installment="' + previewScheme + '"]').toggle(!!(preview && Number(preview.monthly) > 0 && Number(json.calculation.term) > 0));
						});
					}
					state = {offer: typeof json.offer === 'string' ? json.offer : '', intent: typeof json.application_intent === 'string' ? json.application_intent : '', eligible: json.eligible === true, enabled: json.submission_enabled === true};
					quoteUsable = true; lastQuoteKey = quoteKey(Object.assign({}, data, {jet_vnoski: String(json.calculation.term)})); editing = false;
					field.val(state.offer).attr('data-quote-state', 'current');
					if (state.offer) expiry = setTimeout(function () { invalidate(editing); notice('offer_expired'); }, Math.min(1800, Number(json.expires_in) || 0) * 1000);
					if (!state.eligible) notice('monthly_below_minimum');
					else if (!state.enabled) notice('submission_unavailable');
					else status('Офертата е готова.');
					unavailable();
				},
				error: function (xhr) { if (alive && current === sequence) { loading(false); request = null; inFlightKey = null; editing = false; invalidate(preserveVisual); notice(xhr && xhr.responseJSON && xhr.responseJSON.code); if (preserveVisual) status($('#jet_finance_notice').text() + ' Показаните стойности са от последната оферта и не могат да бъдат изпратени.'); } }
			});
		}
		$(document).off('.jetcredit');
		$(document).on('change.jetcredit input.jetcredit', '#product [name^="option["], #product input[name="quantity"]', function () { refresh(false, true); });
		$(document).on('change.jetcredit input.jetcredit', prefix + 'parva', scheduleDownPayment);
		$(document).on('blur.jetcredit', prefix + 'parva', flushDownPayment);
		$(document).on('keydown.jetcredit', prefix + 'parva', function (event) {
			if (event.key === 'Enter' || event.which === 13) { event.preventDefault(); flushDownPayment(); }
		});
		$(document).on('change.jetcredit', prefix + 'vnoski', function () { refresh(false, true); });
		$(document).on('click.jetcredit', '#btn_jet, #btn_jet_card', function () {
			if (busy || pending) return;
			scheme = this.id === 'btn_jet_card' ? 'jet_card' : 'jet';
			$('#jet_step_2').hide(); $('#jet_step_1').show(); $('#jet-product-popup-container').show(); refresh(false, true);
		});
		function closePopup() {
			if (busy || pending) { notice('review_required'); return; }
			cancelEditTimer();
			if (quoteLoading || editing) { ++sequence; if (request && request.abort) request.abort(); request = null; inFlightKey = null; editing = false; loading(false); invalidate(true); }
			$('#jet-product-popup-container').hide();
		}
		$(document).on('click.jetcredit', '#close_jetcredit, #back_jetcredit', closePopup);
		$(document).on('click.jetcredit', '#back2_jetcredit', function (event) {
			event.preventDefault();
			if (busy || pending) return;
			$('#jet_step_2').hide(); $('#jet_step_1').show();
		});
		$(document).on('click.jetcredit', '#buy_cart_jetcredit', function () { if (busy || pending) return; closePopup(); $('#button-cart').trigger('click'); });
		$(document).on('change.jetcredit input.jetcredit', '#uslovia, #uslovia1, #uslovia2, #jet_name, #jet_lastname, #jet_phone, #jet_email, #jet_egn, #jet_uslovia, #jet_uslovia1, #jet_card_egn, #jet_card_uslovia, #jet_card_uslovia1', function () { unavailable(); });
		$(document).on('click.jetcredit', '#buy_jetcredit', function (event) {
			event.preventDefault();
			if (editing || debounce !== null || quoteLoading) { flushDownPayment(); if (!ready()) return; }
			if (!(ready() && consentReady())) { notice('consent_required'); return; }
			$('#jet_step_1').hide(); $('#jet_step_2').show();
		});
		function navigate(redirect) {
			try { const target = new URL(redirect, window.location.href); if (target.origin === window.location.origin && target.searchParams.get('route') === 'extension/mt_jet_credit/success') { window.location.href = target.href; } } catch (ignored) {}
		}
		function submit() {
			if (window.location.protocol !== 'https:') { notice('submission_unavailable'); return; }
			if (!pending && (editing || debounce !== null || quoteLoading)) flushDownPayment();
			if (!canSubmit()) { unavailable(); return; }
			let data = pending;
			if (!data) {
				data = {jet_csrf: field.attr('data-csrf'), jet_intent: state.intent, jet_offer: state.offer, jet_card: scheme === 'jet_card' ? '1' : '0', jet_parva: downPayment(true), jet_vnoski: $(prefix + 'vnoski').val()};
				if (context === 'product') { data.jet_product_id = field.attr('data-product-id'); data.jet_quantity = $('#product input[name="quantity"]').val() || '1'; data.option = selection(); }
				const consentPrefix = context === 'checkout' ? prefix.slice(1) : 'jet_';
				data[consentPrefix + 'egn'] = $(context === 'checkout' ? prefix + 'egn' : '#jet_egn').val();
				data[consentPrefix + 'uslovia'] = $(context === 'checkout' ? prefix + 'uslovia' : '#uslovia').prop('checked') ? '1' : '0';
				data[consentPrefix + 'uslovia1'] = $(context === 'checkout' ? prefix + 'uslovia1' : '#uslovia1').prop('checked') ? '1' : '0';
				if (context !== 'checkout') {
					data.jet_uslovia2 = $('#uslovia2').prop('checked') ? '1' : '0';
					data.jet_name = $('#jet_name').val(); data.jet_lastname = $('#jet_lastname').val(); data.jet_phone = $('#jet_phone').val(); data.jet_email = $('#jet_email').val(); data.jet_lname = $('#jet_lname').val();
				}
			}
			busy = true; pending = data; loading(false); unavailable();
			status('Регистриране на заявката…');
			const route = context === 'checkout' ? 'extension/payment/' + scheme + '/confirm' : 'extension/mt_jet_credit/jetcredit/' + (context === 'product' ? 'jet_send' : 'jet_send_cart');
			const submittedRevision = ++submissionRevision;
			$.ajax({url: 'index.php?route=' + route, type: 'POST', dataType: 'json', timeout: 180000, data: data,
				success: function (json) {
					if (!alive || submittedRevision !== submissionRevision) return;
					busy = false; loading(false);
					if (json && json.success === 'success' && (json.code === 'manual_registered' || json.code === 'transport_accepted')) {
						notice(json.code); pending = null; state.enabled = false; unavailable();
						navigate(json.redirect);
					} else { notice('review_required'); unavailable(); }
				},
				error: function (xhr) {
					if (!alive || submittedRevision !== submissionRevision) return;
					busy = false; loading(false);
					if (xhr && xhr.status === 400) { pending = null; notice(xhr.responseJSON && xhr.responseJSON.code); }
					else { notice('review_required'); $('#buy2_jetcredit').text('Провери резултата'); $('#button-confirm').val('Провери резултата'); }
					unavailable();
				}
			});
		}
		$(document).on('click.jetcredit', '#jet_new_purchase', function (event) {
			event.preventDefault();
			if (busy || !completedIntent || window.location.protocol !== 'https:') return;
			if (!transition) {
				if (!window.crypto || !window.crypto.getRandomValues) { notice('submission_unavailable'); return; }
				transition = Array.from(window.crypto.getRandomValues(new Uint8Array(32)), value => value.toString(16).padStart(2,'0')).join('');
			}
			busy = true; const transitionSequence = ++sequence; if (request && request.abort) request.abort(); invalidate(); $('#jet_new_purchase').prop('disabled',true);
			const prior = completedIntent;
			$.ajax({url:'index.php?route=extension/mt_jet_credit/jetcredit/new_purchase',type:'POST',dataType:'json',data:{jet_csrf:field.attr('data-csrf'),jet_context:context,jet_product_id:field.attr('data-product-id'),jet_intent:prior,jet_transition:transition},
				success:function(json){
					if (!alive || sequence!==transitionSequence) return;
					if (!json || json.success!=='success' || json.previous_intent!==prior || typeof json.application_intent!=='string' || !/^[a-f0-9]{64}$/.test(json.application_intent) || json.application_intent===prior) { busy=false; notice('review_required'); $('#jet_new_purchase').prop('disabled',false); return; }
					$('#uslovia, #uslovia1, #uslovia2, #jet_uslovia, #jet_uslovia1, #jet_card_uslovia, #jet_card_uslovia1').prop('checked',false); $('#jet_egn, #jet_card_egn').val('');
					application=json.application_intent; field.attr('data-intent',application); completedIntent=''; pending=null; transition=''; $('#jet_new_purchase').remove(); $('#jet_previous_result').remove();
					if (context==='checkout') {
						// Native confirm constructs fresh server order data; the helper reserves the new intent.
						$.ajax({url:'index.php?route=checkout/confirm',type:'POST',data:{jet_intent:application,jet_csrf:field.attr('data-csrf')},success:function(){if(alive && sequence===transitionSequence){busy=false;refresh(true);}},error:function(){if(alive && sequence===transitionSequence){busy=false;notice('review_required');}}});
					} else { busy=false; refresh(true); }
				},
				error:function(){if(!alive || sequence!==transitionSequence)return;busy=false;notice('review_required');$('#jet_new_purchase').prop('disabled',false);}
			});
		});
		$(document).on('click.jetcredit', '#buy2_jetcredit, #button-confirm', function (event) { event.preventDefault(); submit(); });
		active = {refresh: refresh, getState: function () { return {offer: ready() ? state.offer : '', eligible: ready() && state.eligible}; }, destroy: function () { alive=false; cancelEditTimer(); editing=false; $('#jet_new_purchase').remove(); $('#jet_previous_result').remove(); ++sequence; if (request && request.abort) request.abort(); busy=false; loading(false); invalidate(); $(document).off('.jetcredit'); }};
		unavailable(); refresh(true);
		return active;
	}
	window.JetCreditOffers = {mount: mount};
})(window, jQuery);
