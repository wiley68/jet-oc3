(function (window, $) {
	'use strict';
	if (window.JetCreditOffers) return;
	let active = null;
	const messages = {
		non_eur_currency: 'Кредитното финансиране е достъпно само в EUR.',
		invalid_eur_base: 'Кредитното финансиране временно не е достъпно.',
		required_options: 'Моля, изберете задължителните опции на продукта.',
		invalid_options: 'Проверете избраните опции на продукта.',
		invalid_quantity: 'Проверете количеството на продукта.',
		out_of_stock: 'Избраното количество не е налично.',
		principal_below_minimum: 'Финансираната главница е под допустимия минимум в EUR.',
		monthly_below_minimum: 'Показаната месечна вноска трябва да е поне 10 EUR.',
		invalid_term: 'Изберете допустим срок за тази покупка.',
		invalid_down_payment: 'Първоначалната вноска трябва да е неотрицателна и по-малка от цената.',
		invalid_money: 'Въведете сума в EUR с най-много два знака след десетичната точка.',
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
		let alive = true;
		let application = field.attr('data-intent') || '', transition = '', completedIntent = '';
		let sequence = 0, request = null, expiry = null, state = {offer: '', intent: '', eligible: false, enabled: false}, busy = false, pending = null;
		function unavailable() {
			const ready = window.location.protocol === 'https:' && !busy && state.enabled && state.eligible && !!state.offer && !!state.intent;
			const consent = context === 'checkout' ? $(prefix + 'uslovia').prop('checked') && $(prefix + 'uslovia1').prop('checked') && $(prefix + 'egn').val() : $('#uslovia').prop('checked') && $('#uslovia1').prop('checked') && $('#uslovia2').prop('checked') && $('#jet_egn').val() && $('#jet_name').val() && $('#jet_lastname').val() && $('#jet_phone').val() && $('#jet_email').val();
			$('#buy_jetcredit').prop('disabled', !ready);
			$('#buy2_jetcredit').prop('disabled', busy || (!pending && (!ready || !consent)));
			$('#button-confirm').prop('disabled', busy || (!pending && (!ready || !consent)));
		}
		function notice(code) {
			$('#jet_finance_notice').text(typeof code === 'string' && Object.prototype.hasOwnProperty.call(messages, code) ? messages[code] : 'Неуспешно изчисление. Проверете данните и опитайте отново.');
		}
		function invalidate() {
			state = {offer: '', intent: '', eligible: false, enabled: false};
			field.val('');
			show({amount: '', principal: '', monthly: '', gpr: '', glp: '', total: '', term: ''});
			$('#jet_vnoska_card').text('');
			if (expiry) clearTimeout(expiry);
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
		function refresh(initial) {
			if (busy || pending) return;
			const current = ++sequence;
			if (request && request.abort) request.abort();
			invalidate();
			const data = {jet_intent: application, jet_context: context, jet_card: scheme === 'jet_card' ? '1' : '0', jet_csrf: field.attr('data-csrf'), jet_parva: $(prefix + 'parva').val(), jet_vnoski: initial ? '' : $(prefix + 'vnoski').val()};
			if (context === 'product') {
				data.jet_product_id = field.attr('data-product-id');
				data.jet_quantity = $('#product input[name="quantity"]').val() || $('input[name="quantity"]').first().val() || '1';
				data.option = selection();
			}
			$('#jet_finance_notice').text('Изчисляване на офертата…');
			request = $.ajax({
				url: 'index.php?route=extension/mt_jet_credit/jetcredit/' + (context === 'product' ? 'jet_calculate' : 'jet_calculate_cart'),
				type: 'POST', dataType: 'json', data: data,
				success: function (json) {
					if (current !== sequence) return;
					if (json && json.application_intent !== application) return;
					if (json && json.success === 'success' && (json.existing_outcome === 'manual_registered' || json.existing_outcome === 'transport_accepted')) {
						completedIntent = application; notice(json.existing_outcome); $('#jet-product-button-container').show();
						$('#jet_new_purchase').remove(); $('#jet_previous_result').remove();
						$('#jet_finance_notice').after($('<button></button>').attr('id','jet_new_purchase').attr('type','button').text('Започни нова покупка на изплащане'));
						try { const target = new URL(json.redirect,window.location.href); if (target.origin===window.location.origin && target.searchParams.get('route')==='extension/mt_jet_credit/success') $('#jet_new_purchase').after($('<a></a>').attr('id','jet_previous_result').attr('href',target.href).text('Виж предходната заявка')); } catch (ignored) {}
						return;
					}
					if (!json || json.success !== 'success' || json.currency !== 'EUR' || json.context !== context || json.scheme !== scheme || !json.calculation || !Array.isArray(json.allowed_terms)) { notice(json && json.code); return; }
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
					}
					state = {offer: typeof json.offer === 'string' ? json.offer : '', intent: typeof json.application_intent === 'string' ? json.application_intent : '', eligible: json.eligible === true, enabled: json.submission_enabled === true};
					field.val(state.offer);
					if (state.offer) expiry = setTimeout(function () { invalidate(); notice('offer_expired'); }, Math.min(1800, Number(json.expires_in) || 0) * 1000);
					if (!state.eligible) notice('monthly_below_minimum');
					else if (!state.enabled) notice('submission_unavailable');
					else $('#jet_finance_notice').text('Офертата е готова.');
					unavailable();
				},
				error: function (xhr) { if (current === sequence) { invalidate(); notice(xhr && xhr.responseJSON && xhr.responseJSON.code); } }
			});
		}
		$(document).off('.jetcredit');
		$(document).on('change.jetcredit input.jetcredit', '#product [name^="option["], #product input[name="quantity"]', function () { refresh(false); });
		$(document).on('change.jetcredit input.jetcredit', prefix + 'parva', function () { refresh(false); });
		$(document).on('change.jetcredit', prefix + 'vnoski', function () { refresh(false); });
		$(document).on('click.jetcredit', '#btn_preizcisli, #btn_card_preizcisli', function () { refresh(false); });
		$(document).on('click.jetcredit', '#btn_jet, #btn_jet_card', function () {
			scheme = this.id === 'btn_jet_card' ? 'jet_card' : 'jet';
			$('#jet_step_2').hide(); $('#jet_step_1').show(); $('#jet-product-popup-container').show(); refresh(false);
		});
		$(document).on('click.jetcredit', '#close_jetcredit, #back_jetcredit', function () { $('#jet-product-popup-container').hide(); });
		$(document).on('click.jetcredit', '#buy_cart_jetcredit', function () { $('#button-cart').trigger('click'); $('#jet-product-popup-container').hide(); });
		$(document).on('change.jetcredit input.jetcredit', '#uslovia, #uslovia1, #uslovia2, #jet_name, #jet_lastname, #jet_phone, #jet_email, #jet_egn, #jet_uslovia, #jet_uslovia1, #jet_card_egn, #jet_card_uslovia, #jet_card_uslovia1', function () { unavailable(); });
		$(document).on('click.jetcredit', '#buy_jetcredit', function (event) {
			event.preventDefault();
			if (!$('#uslovia').prop('checked') || !$('#uslovia1').prop('checked')) { notice('consent_required'); return; }
			$('#jet_step_1').hide(); $('#jet_step_2').show();
		});
		function navigate(redirect) {
			try { const target = new URL(redirect, window.location.href); if (target.origin === window.location.origin && target.searchParams.get('route') === 'extension/mt_jet_credit/success') { window.location.href = target.href; } } catch (ignored) {}
		}
		function submit() {
			if (window.location.protocol !== 'https:') { notice('submission_unavailable'); return; }
			if (busy || (!pending && (!state.enabled || !state.offer || !state.intent || !state.eligible))) return;
			let data = pending;
			if (!data) {
				data = {jet_csrf: field.attr('data-csrf'), jet_intent: state.intent, jet_offer: state.offer, jet_card: scheme === 'jet_card' ? '1' : '0', jet_parva: $(prefix + 'parva').val(), jet_vnoski: $(prefix + 'vnoski').val()};
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
			busy = true; pending = data; unavailable();
			$('#jet_finance_notice').text('Регистриране на заявката…');
			const route = context === 'checkout' ? 'extension/payment/' + scheme + '/confirm' : 'extension/mt_jet_credit/jetcredit/' + (context === 'product' ? 'jet_send' : 'jet_send_cart');
			$.ajax({url: 'index.php?route=' + route, type: 'POST', dataType: 'json', timeout: 180000, data: data,
				success: function (json) {
					busy = false;
					if (json && json.success === 'success' && (json.code === 'manual_registered' || json.code === 'transport_accepted')) {
						notice(json.code); pending = null; state.enabled = false; unavailable();
						navigate(json.redirect);
					} else { notice('review_required'); unavailable(); }
				},
				error: function (xhr) {
					busy = false;
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
		active = {refresh: refresh, getState: function () { return {offer: state.offer, eligible: state.eligible}; }, destroy: function () { alive=false; $('#jet_new_purchase').remove(); $('#jet_previous_result').remove(); ++sequence; if (request && request.abort) request.abort(); invalidate(); $(document).off('.jetcredit'); }};
		unavailable(); refresh(true);
		return active;
	}
	window.JetCreditOffers = {mount: mount};
})(window, jQuery);
