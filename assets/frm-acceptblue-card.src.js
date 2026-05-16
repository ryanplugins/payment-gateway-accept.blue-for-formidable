/* global frmAbCardConfigs */
( function() {
	'use strict';

	/**
	 * Module-level registry for the external Accept.Blue tokenization SDK.
	 *
	 * Problem: when multiple card fields exist on the same page (or the same
	 * page is visited after a soft navigation), each initCardField() call
	 * invokes loadScript(). The original code checked for an existing <script>
	 * tag and polled for window.HostedTokenization, but it did NOT prevent a
	 * second <script> tag from being injected if the first had already finished
	 * loading. The SDK throws "window.HostedTokenization is already defined"
	 * when executed a second time because it assigns to that global without a
	 * guard, causing a fatal JS error that breaks all card fields on the page.
	 *
	 * Fix: track the SDK load state in a shared closure variable (_sdkState).
	 * • 'idle'    – not yet requested
	 * • 'loading' – <script> tag injected, waiting for onload
	 * • 'ready'   – SDK fully loaded; new callers get cb() synchronously
	 *
	 * Callbacks queued while 'loading' are flushed once onload fires.
	 */
	var _sdkState     = 'idle';    // 'idle' | 'loading' | 'ready'
	var _sdkCallbacks = [];        // queued callbacks waiting for SDK load

	/**
	 * Boot all card field instances declared by PHP via wp_localize_script.
	 * frmAbCardConfigs is an array - one entry per Accept.Blue card field on the page.
	 */
	if ( typeof frmAbCardConfigs === 'undefined' || ! Array.isArray( frmAbCardConfigs ) ) {
		return;
	}

	frmAbCardConfigs.forEach( function( CFG ) {
		// wp_localize_script stringifies everything - cast back to proper types.
		CFG.amountFixed     = parseFloat( CFG.amountFixed )  || 0;
		CFG.testMode        = CFG.testMode        === '1' || CFG.testMode        === true;
		CFG.showSurcharge   = CFG.showSurcharge   === '1' || CFG.showSurcharge   === true;
		CFG.showCardDetails = CFG.showCardDetails === '1' || CFG.showCardDetails === true;
		CFG.debugLog        = CFG.debugLog        === '1' || CFG.debugLog        === true;
		if (CFG.debugLog) console.log( '[Accept.Blue] CFG loaded:', {
			fieldId: CFG.fieldId,
			amountType: CFG.amountType,
			amountFixed: CFG.amountFixed,
			amountFieldId: CFG.amountFieldId,
			showSurcharge: CFG.showSurcharge,
			showCardDetails: CFG.showCardDetails
		} );
		initCardField( CFG );
	} );

	function initCardField( CFG ) {

		var LOG = '[Accept.Blue Card #' + CFG.fieldId + ']';

		// ── 3DS loader helpers (show/hide spinning overlay) ──────────────────
		function showLoader() {
			if (!CFG.threeDsLoaderId) return;
			var l = document.getElementById(CFG.threeDsLoaderId);
			if (l) l.style.display = 'flex';
		}
		function hideLoader() {
			if (!CFG.threeDsLoaderId) return;
			var l = document.getElementById(CFG.threeDsLoaderId);
			if (l) l.style.display = 'none';
		}

		// ── Submit spinner (shown while payment AJAX runs + form submits) ─────
		function showSubmitLoader() {
			var l = document.getElementById(CFG.submitLoaderId);
			if (l) l.style.display = 'flex';
		}
		function hideSubmitLoader() {
			var l = document.getElementById(CFG.submitLoaderId);
			if (l) l.style.display = 'none';
		}

		// ── 3DS: Collect browser info once on load ───────────────────────────────
		// This data is required by 3DS2 (EMV 3-D Secure). Collected passively —
		// no user interaction needed. Stored in hidden field before form submit.
		var _threeDsBrowserInfo = (function() {
			try {
				return {
					java_enabled    : navigator.javaEnabled ? navigator.javaEnabled() : false,
					language        : navigator.language || navigator.userLanguage || 'en',
					color_depth     : screen.colorDepth || 24,
					screen_height   : screen.height,
					screen_width    : screen.width,
					timezone_offset : new Date().getTimezoneOffset(),
					user_agent      : navigator.userAgent ? navigator.userAgent.substring(0, 255) : '',
				};
			} catch(e) { return {}; }
		})();
		var _ht = null;
		var _ready = false;

		function log()    { if (!CFG.debugLog) return; var a = [].slice.call(arguments); a.unshift(LOG);        console.log.apply(console, a); }
		function logErr() { var a = [].slice.call(arguments); a.unshift(LOG+' ERR'); console.error.apply(console, a); }
		function logWarn(){ if (!CFG.debugLog) return; var a = [].slice.call(arguments); a.unshift(LOG+' WARN');console.warn.apply(console, a); }

		// log3ds() - always-on 3DS diagnostic logger (ignores CFG.debugLog)
		function log3ds(event, data) {
			return; // 3DS console labels suppressed
			var badges = {
				INIT:          'background:#1a4a7a;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold;',
				READY:         'background:#166534;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold;',
				BROWSER_INFO:  'background:#0369a1;color:#fff;padding:2px 6px;border-radius:3px;',
				SUBMIT:        'background:#0369a1;color:#fff;padding:2px 6px;border-radius:3px;',
				CHALLENGE:     'background:#b45309;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold;',
				FRICTIONLESS:  'background:#7c3aed;color:#fff;padding:2px 6px;border-radius:3px;',
				OVERLAY_SHOW:  'background:#b45309;color:#fff;padding:2px 6px;border-radius:3px;',
				OVERLAY_HIDE:  'background:#555;color:#fff;padding:2px 6px;border-radius:3px;',
				DISABLED:      'background:#555;color:#fff;padding:2px 6px;border-radius:3px;',
				ERROR:         'background:#dc2626;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold;',
			};
			var icons = {
				INIT:'[INIT]', READY:'[READY]', BROWSER_INFO:'[BROWSER]', SUBMIT:'[SUBMIT]',
				CHALLENGE:'[CHALLENGE]', FRICTIONLESS:'[FRICTIONLESS]', OVERLAY_SHOW:'[OVERLAY ON]',
				OVERLAY_HIDE:'[OVERLAY OFF]', DISABLED:'[DISABLED]', ERROR:'[ERROR]',
			};
			var badge = badges[event] || 'background:#333;color:#fff;padding:2px 6px;border-radius:3px;';
			var icon  = icons[event]  || '[3DS]';
			var title = 'Accept.Blue 3DS';
			if (data !== null && data !== undefined && typeof data === 'object' && Object.keys(data).length > 0) {
				console.groupCollapsed('%c' + title + ' %c' + icon, badge, 'color:#555;font-weight:normal;');
				Object.keys(data).forEach(function(k) { console.log('  ' + k + ':', data[k]); });
				console.groupEnd();
			} else {
				var msg = data !== undefined && data !== null ? ' — ' + String(data) : '';
				console.log('%c' + title + ' %c' + icon + msg, badge, 'color:#444;');
			}
		}


		var _submitted = false; // set true after fetch submit to suppress iframe re-init errors

		function showError(msg) {
			if (_submitted) return; // suppress post-submit iframe errors
			var el = document.getElementById(CFG.errorId);
			if (el) {
				el.innerHTML =
					'<span style="flex-shrink:0;font-size:1.1em;">&#x26A0;</span>'
					+ '<span>' + msg + '</span>';
				el.style.display = 'flex';
				// Scroll error into view so user sees it
				el.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
			// Hide surcharge fee box on error
			var _sc = document.getElementById(CFG.surchargeId);
			if (_sc) _sc.style.display = 'none';
			logErr('UI error:', msg);
		}
		function hideError() {
			var el = document.getElementById(CFG.errorId);
			if (el) el.style.display = 'none';
			// Restore surcharge if it was previously visible
			var _sc2 = document.getElementById(CFG.surchargeId);
			if (_sc2 && _surchargeAmount > 0) _sc2.style.display = '';
		}

		// -- Confirmation modal IDs ------------------------------------------
		var _surchargeAmount = 0;
		var _lastBaseAmount  = 0;     // base amount at submit time (for on-change surcharge)
		var _lastCardEvent   = null;  // last change/input event data from iFrame
		var _confirmedNonce  = '';   // nonce obtained BEFORE showing modal
		var MODAL_ID     = 'frm_ab_lite_confirm_modal_'         + CFG.fieldId;
		var CONFIRM_PAY  = 'frm_ab_lite_confirm_pay_'           + CFG.fieldId;
		var CONFIRM_CAN  = 'frm_ab_lite_confirm_cancel_'        + CFG.fieldId;
		var CONFIRM_AMT  = 'frm_ab_lite_confirm_amount_'        + CFG.fieldId;
		var CONFIRM_SUR  = 'frm_ab_lite_confirm_surcharge_'     + CFG.fieldId;
		var CONFIRM_TOT  = 'frm_ab_lite_confirm_total_'         + CFG.fieldId;
		var CONFIRM_SURR = 'frm_ab_lite_confirm_surcharge_row_' + CFG.fieldId;
		var CONFIRM_TOTR = 'frm_ab_lite_confirm_total_row_'     + CFG.fieldId;
		var CONFIRM_TOTR2= 'frm_ab_lite_confirm_total_row2_'    + CFG.fieldId;
		var SURAMT_ID    = CFG.surchargeId + '_amount';

		function calcSurcharge(result, baseAmount) {
			// accept.blue getSurcharge() returns {surcharge:{type,value}, binType}
			// type="percent" -> value is %, type="flat" -> value is dollar amount
			if (!result || !result.surcharge) return 0;
			var s = result.surcharge;
			if (!s.type || !s.value || parseFloat(s.value) <= 0) return 0;
			if (s.type === 'percent') {
				return Math.round(parseFloat(baseAmount) * parseFloat(s.value) / 100 * 100) / 100;
			}
			if (s.type === 'flat' || s.type === 'fixed') {
				return Math.round(parseFloat(s.value) * 100) / 100;
			}
			// Legacy: if .amount is directly provided
			if (s.amount && parseFloat(s.amount) > 0) return parseFloat(s.amount);
			return 0;
		}

		function showConfirmModal(baseAmount, cardData, surchargeResult) {
			_surchargeAmount = 0;

			// -- Surcharge --
			_surchargeAmount = calcSurcharge(surchargeResult, baseAmount);
			var surAmtEl = document.getElementById(SURAMT_ID);
			if (surAmtEl) surAmtEl.value = _surchargeAmount;
			if (_surchargeAmount > 0) {
				// Update inline surcharge badge below card field
				displaySurcharge(surchargeResult, baseAmount);
			}

			var total = parseFloat(baseAmount) + _surchargeAmount;
			var el = function(id) { return document.getElementById(id); };

			// Amount row
			if (el(CONFIRM_AMT)) el(CONFIRM_AMT).textContent = '$' + parseFloat(baseAmount).toFixed(2);

			// Surcharge row: shown when showSurcharge is enabled
			if (CFG.showSurcharge) {
				if (_surchargeAmount > 0) {
					var s = surchargeResult && surchargeResult.surcharge ? surchargeResult.surcharge : null;
					var pctLabel = (s && s.type === 'percent' && parseFloat(s.value) > 0)
						? ' (' + s.value + '%)' : '';
					if (el(CONFIRM_SUR))   el(CONFIRM_SUR).textContent    = '+$' + _surchargeAmount.toFixed(2) + pctLabel;
					if (el(CONFIRM_SURR))  el(CONFIRM_SURR).style.display  = '';
					if (el(CONFIRM_TOT))   el(CONFIRM_TOT).textContent    = '$' + total.toFixed(2);
					if (el(CONFIRM_TOTR))  el(CONFIRM_TOTR).style.display  = '';
					if (el(CONFIRM_TOTR2)) el(CONFIRM_TOTR2).style.display = '';
				} else {
					if (el(CONFIRM_SUR))   el(CONFIRM_SUR).textContent    = 'None';
					if (el(CONFIRM_SURR))  el(CONFIRM_SURR).style.display  = '';
					if (el(CONFIRM_TOTR))  el(CONFIRM_TOTR).style.display  = 'none';
					if (el(CONFIRM_TOTR2)) el(CONFIRM_TOTR2).style.display = 'none';
				}
			} else {
				if (el(CONFIRM_SURR))  el(CONFIRM_SURR).style.display  = 'none';
				if (el(CONFIRM_TOTR))  el(CONFIRM_TOTR).style.display  = 'none';
				if (el(CONFIRM_TOTR2)) el(CONFIRM_TOTR2).style.display = 'none';
			}

			// Card details (populated from getData() result passed in)
			var cardEl = el('frm_ab_lite_confirm_card_' + CFG.fieldId);
			if (cardEl) {
				if ( ! CFG.showCardDetails ) {
					cardEl.style.display = 'none';
				} else if (cardData) {
					// Verified getData() keys from accept.blue v0.3: maskedCard, last4, cardType, expiryMonth, expiryYear
					var last4  = cardData.last4 || (cardData.maskedCard || '').replace(/\D/g,'').slice(-4) || '';
					var brand  = cardData.cardType || cardData.brand || '';
					var expM   = cardData.expiryMonth || cardData.exp_month || '';
					var expY   = cardData.expiryYear  || cardData.exp_year  || '';
					var expStr = (expM && expY) ? String(expM).padStart(2,'0') + '/' + String(expY).slice(-2) : '';
					if (last4 || brand) {
						var line = brand ? brand + ' ending in ' + last4 : '**** **** **** ' + last4;
						if (expStr) line += '   Exp ' + expStr;
						cardEl.innerHTML = '<span style="font-size:1.1em;margin-right:6px;">&#x1F4B3;</span><strong>' + line + '</strong>';
					} else {
						cardEl.textContent = CFG.i18n.cardSecured;
					}
				} else {
					// Fallback: card data unavailable
					cardEl.textContent = CFG.i18n.cardSecured;
				}
			}

			// Update surcharge label from settings
			var surLabelEl = el('frm_ab_lite_confirm_surcharge_label_' + CFG.fieldId);
			if (surLabelEl && CFG.surchargeLabel) surLabelEl.textContent = CFG.surchargeLabel;

			// ── Recurring notice: populate live amount placeholders ──────────────────
			if (CFG.recurringEnabled) {
				var base = parseFloat(baseAmount);
				if (CFG.scheduleType === 'installment' && CFG.installmentCount > 1) {
					var perPayment = Math.round(base / CFG.installmentCount * 100) / 100;
					var perEl  = el('frm_ab_lite_installment_per_'   + CFG.fieldId);
					var totEl  = el('frm_ab_lite_installment_total_' + CFG.fieldId);
					if (perEl)  perEl.textContent  = '$' + perPayment.toFixed(2);
					if (totEl)  totEl.textContent  = '$' + base.toFixed(2);
					// Also update the Amount row label to show per-payment amount
					if (el(CONFIRM_AMT)) el(CONFIRM_AMT).textContent = '$' + perPayment.toFixed(2) + ' × ' + CFG.installmentCount;
				} else {
					// Subscription: populate the amount span in the notice
					var subAmtEl = el('frm_ab_lite_sub_amount_' + CFG.fieldId);
					if (subAmtEl) subAmtEl.textContent = '$' + base.toFixed(2);
				}
			}

			var modal = el(MODAL_ID);
			if (modal) modal.style.display = 'flex';
			log('Modal shown | base:', baseAmount, '| surcharge:', _surchargeAmount, '| total:', total);
		}

		function hideConfirmModal(clearNonce) {
			var modal = document.getElementById(MODAL_ID);
			if (modal) modal.style.display = 'none';
			if (clearNonce) _confirmedNonce = ''; // only clear when explicitly cancelled
		}

		function wireModalButtons() {
			var payBtn = document.getElementById(CONFIRM_PAY);
			var canBtn = document.getElementById(CONFIRM_CAN);
			if (payBtn && !payBtn._wired) { payBtn.addEventListener('click', proceedAfterConfirm); payBtn._wired = true; }
			if (canBtn && !canBtn._wired) {
				canBtn.addEventListener('click', function(){
					hideConfirmModal(true);
				});
				canBtn._wired = true;
			}
		}
		wireModalButtons();
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', wireModalButtons);
		}

		// -- Submit handler --------------------------------------------------
		// Flow:
		//   1. Amount > 0 guard
		//   2. getNonceToken() + getData() + getSurcharge() run in parallel
		//      If 3DS enabled: challenge fires INSIDE getNonceToken() here,
		//      during the loading spinner — BEFORE the modal opens.
		//   3. All three resolve -> nonce stored, modal shown (auth already done)
		//   4. User clicks Pay Now -> just writes nonce + submits. No waiting.
		function handleSubmit(e) {
			var nonceField = document.getElementById(CFG.nonceId);
			// If a payment error is currently showing, the nonce was already consumed
			// by the failed pre-check. Clear it so we go through the full flow again.
			var errEl = document.getElementById(CFG.errorId);
			var hasError = errEl && errEl.style.display !== 'none' && errEl.innerHTML.trim() !== '';
			if (hasError && nonceField) { nonceField.value = ''; }
			if (nonceField && nonceField.value !== '') {
				log('Nonce already set - passing through to PHP.');
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			hideError();
			if (!_ht) { showError(CFG.i18n.notReady); return; }

			// 1. Resolve base amount
			var baseAmount = resolveAmount();
			log('Amount resolved | type:', CFG.amountType, '| fixed:', CFG.amountFixed, '| baseAmount:', baseAmount);
			if (baseAmount <= 0) {
				logErr('Amount is $0.00 — check Charge Amount in form Actions & Notifications.');
				showError('Payment amount is not configured. Please contact the site administrator.');
				return;
			}

			// 2. Loading state
			var container  = document.querySelector(CFG.containerId);
			var formEl     = container ? container.closest('form') : null;
			var submitBtn  = formEl ? formEl.querySelector('[type="submit"]') : null;
			var origBtnText = submitBtn ? submitBtn.textContent : '';
			if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Verifying...'; }
			_lastBaseAmount = baseAmount;

			// 3a. Write 3DS browser info now so it's ready when getNonceToken runs
			if (CFG.threeDsEnabled && CFG.threeDsDataId && _threeDsBrowserInfo) {
				var threeDsField = document.getElementById(CFG.threeDsDataId);
				if (threeDsField) {
					threeDsField.value = JSON.stringify(_threeDsBrowserInfo);
					log3ds('SUBMIT', {
						status          : 'Browser info written — getNonceToken() will trigger 3DS now',
						frictionless    : CFG.threeDsFrictionless,
						paay            : CFG.paayEnabled,
						screen          : _threeDsBrowserInfo.screen_width + 'x' + _threeDsBrowserInfo.screen_height,
						language        : _threeDsBrowserInfo.language,
						timezone_offset : _threeDsBrowserInfo.timezone_offset,
					});
				}
			}

			// 3b. Helper: read a form field value by its Formidable item_meta field ID
			function fieldVal(fieldId) {
				if (!fieldId) return '';
				var el = document.querySelector('[name="item_meta[' + fieldId + ']"]') ||
				         document.getElementById('field_' + fieldId);
				return el ? (el.value || '').trim() : '';
			}

			// 3c. getNonceToken() first, then verify3DS() if Paay is enabled
			log3ds('CHALLENGE', {
				status      : 'Calling getNonceToken()',
				threeDsEnabled: CFG.threeDsEnabled,
				paay        : CFG.paayEnabled,
				frictionless: CFG.threeDsFrictionless,
				amount      : baseAmount,
			});

			var noncePromise = _ht.getNonceToken()
				.then(function(rawNonce) {
					var nonce = typeof rawNonce === 'string' ? rawNonce
						: (rawNonce && typeof rawNonce === 'object')
							? (rawNonce.nonce || rawNonce.token || rawNonce.id || String(rawNonce))
							: '';
					if (!nonce) throw new Error(CFG.i18n.noToken);
					log('getNonceToken() OK:', nonce.substring(0, 20) + '...');

					// ── verify3DS() — only when Paay is configured ───────────────────
					// Build the data object from mapped form fields and call verify3DS().
					// The SDK fires the 'challenge' event from within verify3DS() if the
					// issuer requires an interactive challenge (OTP / biometric).
					if (CFG.threeDsEnabled && CFG.paayEnabled && typeof _ht.verify3DS === 'function') {
						var threeDsData = {
							amount : parseFloat(baseAmount),
							email  : fieldVal(CFG.fieldEmail),
							billing: {
								first_name : fieldVal(CFG.fieldBillingFirst) || fieldVal(CFG.fieldName).split(' ')[0] || '',
								last_name  : fieldVal(CFG.fieldBillingLast)  || fieldVal(CFG.fieldName).split(' ').slice(1).join(' ') || '',
								street     : fieldVal(CFG.fieldBillingStreet) || fieldVal(CFG.fieldAvsAddress) || '',
								city       : fieldVal(CFG.fieldBillingCity)   || '',
								state      : fieldVal(CFG.fieldBillingState)  || '',
								zip        : fieldVal(CFG.fieldBillingZip)    || fieldVal(CFG.fieldAvsZip) || '',
							},
						};
						// Remove empty strings so Paay doesn't reject them
						Object.keys(threeDsData.billing).forEach(function(k) {
							if (!threeDsData.billing[k]) delete threeDsData.billing[k];
						});
						if (!threeDsData.email) delete threeDsData.email;
						if (CFG.threeDsFrictionless) threeDsData.preference = 'no_challenge';

						// Scroll to top, then show spinner while Paay 3DS loads
						window.scrollTo(0, 0);
						document.documentElement.scrollTop = 0;
						document.body.scrollTop = 0;
						showLoader();
						log3ds('CHALLENGE', 'Loader shown — waiting for SDK challenge window.');
						log3ds('CHALLENGE', {
							status      : 'Calling verify3DS() — scrolled to top, SDK renders challenge',
							amount      : threeDsData.amount,
							email       : threeDsData.email || '(none)',
							frictionless: CFG.threeDsFrictionless,
							billing     : threeDsData.billing,
						});

						return _ht.verify3DS(threeDsData)
							.then(function(result) {
								var status = result && result.status ? result.status.toUpperCase() : '';

								hideLoader();
								log3ds('READY', {
									status      : 'verify3DS() complete',
									auth_status : status,
									eci         : result && result.eci,
									cavv        : result && result.cavv ? result.cavv.substring(0,8)+'...' : '(none)',
									ds_trans_id : result && result.ds_trans_id,
								});

								// Only Y (authenticated) and A (attempted) are acceptable.
								// N = failed, U = unavailable, R = rejected — block and show error.
								var BLOCKED = { 'N': 'Authentication failed', 'U': 'Authentication unavailable', 'R': 'Authentication rejected by issuer' };
								if (status && BLOCKED[status]) {
									log3ds('ERROR', { status: '3DS blocked — status: ' + status, reason: BLOCKED[status] });
									throw new Error('3D Secure ' + BLOCKED[status].toLowerCase() + ' (status: ' + status + '). Please try a different card or contact your bank.');
								}

								// Store result for PHP charge payload
								var resultField = document.getElementById(CFG.threeDsResultId);
								if (resultField && result) resultField.value = JSON.stringify(result);
								return nonce;
							})
							.catch(function(err) {
								hideLoader();
								// 404 = Paay not provisioned on this account / sandbox
								// Fall back gracefully to native 3DS (getNonceToken already ran)
								var msg = (err && err.message) ? err.message : String(err);
								var is404 = msg.indexOf('404') !== -1 || msg.indexOf('Not Found') !== -1;
								if (is404) {
									log3ds('ERROR', 'verify3DS() 404 — Paay not provisioned on this account. Falling back to native 3DS.');
									return nonce; // proceed with native nonce
								}
								log3ds('ERROR', { status: 'verify3DS() failed', error: msg });
								throw err; // re-throw non-404 errors
							});

					} else if (CFG.threeDsEnabled && !CFG.paayEnabled) {
						// ── Native 3DS path (no Paay key) ────────────────────────────────
						// getNonceToken() already ran the native 3DS flow above.
						// The 'challenge' event handler shows our modal + the SDK renders
						// its own challenge UI — we don't need to do anything more here.
						log3ds('READY', {
							status : 'Native 3DS complete via getNonceToken()',
							nonce  : nonce.substring(0, 16) + '...',
						});
						return nonce;
					} else {
						// 3DS disabled
						log3ds('DISABLED', '3DS off — no verify3DS needed.');
						return nonce;
					}
				});

			var cardDataPromise = (function() {
				if (!CFG.showCardDetails) return Promise.resolve(null);
				if (typeof _ht.getData === 'function') {
					return _ht.getData()
						.then(function(d) { log('getData():', d); return (d && d.result) ? d.result : d; })
						.catch(function(e) {
							logWarn('getData() failed, using last event:', e.message || e);
							return (_lastCardEvent && _lastCardEvent.result) ? _lastCardEvent.result : null;
						});
				}
				var evtData = (_lastCardEvent && _lastCardEvent.result) ? _lastCardEvent.result : null;
				log('getData() not available, using last event:', evtData);
				return Promise.resolve(evtData);
			})();

			var surchargePromise = (CFG.showSurcharge && _ready)
				? _ht.getSurcharge()
					.then(function(r) { log('getSurcharge():', JSON.stringify(r)); return r; })
					.catch(function(se) { logWarn('getSurcharge skipped:', se.message || se); return null; })
				: Promise.resolve(null);

			// 4. All three settle — hide overlay, show confirm modal
			Promise.all([noncePromise, cardDataPromise, surchargePromise])
				.then(function(results) {
					var nonce = results[0], cardData = results[1], surcharge = results[2];

					log3ds('READY', '3DS flow complete — showing confirm modal.');

					// Store authenticated nonce — Pay Now just writes + submits
					_confirmedNonce = nonce;

					if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = origBtnText; }
					showConfirmModal(baseAmount, cardData, surcharge);
				})
				.catch(function(err) {
					hideLoader();
					if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = origBtnText; }
					var msg = (err && err.message) ? err.message : String(err);
					// 401 / nonce errors mean the API key is wrong or mode mismatch
					var isApiKeyError = msg.toLowerCase().indexOf('nonce') !== -1
					                 || msg.toLowerCase().indexOf('unauthorized') !== -1
					                 || msg.toLowerCase().indexOf('401') !== -1;
					if (isApiKeyError) {
						showError('Payment processor connection failed. Please verify your Accept.Blue API key is correct and matches the selected mode (sandbox/live).');
						return;
					}
					if (CFG.threeDsEnabled) {
						log3ds('ERROR', { status: 'Failed during 3DS / tokenization', error: msg });
						showError('3D Secure authentication failed: ' + msg);
					} else {
						logErr('Tokenization failed:', msg);
						showError(msg || CFG.i18n.cardFailed);
					}
				});
		}


		// Called when user clicks "Pay Now" in the modal.
		// 3DS is already done — _confirmedNonce is authenticated.
		// This just writes the nonce (+ vault nonce for recurring) and submits.
		function proceedAfterConfirm() {
			hideConfirmModal(false);

			var nonce = _confirmedNonce;
			_confirmedNonce = '';
			if (!nonce) { showError(CFG.i18n.noToken); return; }

			var nonceField = document.getElementById(CFG.nonceId);
			if (!nonceField) { showError('Form not found.'); return; }

			log('Pay Now — 3DS already complete, writing nonce and submitting.');

			// For recurring, the precheck AJAX handler vaults the nonce
			// (customer + payment-method) before form submission.
			// The same single nonce is passed to the precheck via ab_nonce —
			// no second vault-nonce fetch is needed.
			nonceField.value = nonce;
			log('Nonce stored. Submitting...');
			doSubmit();

			function doSubmit() {
				var c2 = document.querySelector(CFG.containerId);
				var f2 = c2 ? c2.closest('form') : null;
				if (!f2) { showError('Form not found.'); return; }

				var submitBtnD = f2.querySelector('[type="submit"]');
				var origTextD  = submitBtnD ? submitBtnD.textContent : '';
				function reEnableBtn() {
					if (submitBtnD) { submitBtnD.disabled = false; submitBtnD.textContent = origTextD; }
					hideSubmitLoader();
				}

				// Show the processing overlay immediately
				showSubmitLoader();

				// ── Pre-submit payment pattern ────────────────────────────────────
				// 1. Call our own AJAX endpoint to charge the card BEFORE Formidable
				//    creates an entry. If it fails: show error, stay on form, done.
				//    If it succeeds: mark paymentPassed=true and let Formidable submit.
				// 2. frm_ab_lite_process_payment detects the pre-auth transient and skips
				//    re-charging — just records the existing charge result.
				var nonceEl  = document.getElementById(CFG.nonceId);
				var abNonce  = nonceEl ? nonceEl.value : '';
				var amount   = resolveAmount();

				if (!abNonce) { showError(CFG.i18n.noToken); reEnableBtn(); return; }

				_submitted = true; // suppress iframe re-init errors

				jQuery.post(
					(typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
					{
						action      : 'frm_ab_lite_precheck_payment',
						nonce       : CFG.precheckNonce,
						ab_nonce    : abNonce,
						ab_amount   : amount,
						ab_currency : CFG.currency || 'USD',
						ab_capture  : CFG.capture ? '1' : '0',
						form_id     : CFG.formId   || '',
						action_id   : CFG.actionId || '',
					},
					function(response) {
						if (!response.success) {
							// ❌ Payment failed — reset ALL state so retry shows modal
							_submitted = false;
							_confirmedNonce = '';
							// Clear nonce field so handleSubmit doesn't skip the modal on retry
							var nfReset = document.getElementById(CFG.nonceId);
							if (nfReset) nfReset.value = '';
							var tkReset = document.getElementById('frm_ab_lite_trans_key_' + CFG.fieldId);
							if (tkReset) tkReset.value = '';
							showError(response.data && response.data.message
								? response.data.message
								: CFG.i18n.cardFailed);
							reEnableBtn();
							return;
						}
						// ✅ Payment authorised — store trans_key in hidden field,
						// then let Formidable submit normally to create the entry.
						var transKey = response.data && response.data.trans_key ? response.data.trans_key : '';
						var tkField  = document.getElementById('frm_ab_lite_trans_key_' + CFG.fieldId);
						if (tkField) tkField.value = transKey;

						// Submit to Formidable — entry creation, confirmations, emails all run normally
						if (typeof f2.requestSubmit === 'function') { f2.requestSubmit(); } else { f2.submit(); }
					}
				);
			}
		}
		// -- Amount resolution -----------------------------------------------
		function resolveAmount() {
			var baseAmount = 0;
			// Primary: data attributes on the wrapper div (written by PHP, bypasses wp_localize_script)
			var wrapEl = document.getElementById('frm_ab_lite_wrap_' + CFG.fieldId);
			var dataAmtType  = wrapEl ? wrapEl.getAttribute('data-amount-type')  : null;
			var dataAmtFixed = wrapEl ? wrapEl.getAttribute('data-amount-fixed') : null;
			var dataAmtField = wrapEl ? wrapEl.getAttribute('data-amount-field') : null;
			var amtType  = dataAmtType  || CFG.amountType;
			var amtFixed = dataAmtFixed !== null ? parseFloat(dataAmtFixed) : CFG.amountFixed;
			var amtField = (dataAmtField !== null && dataAmtField !== '') ? dataAmtField : CFG.amountFieldId;

			if (amtType === 'fixed' && amtFixed > 0) {
				baseAmount = amtFixed;
			} else if (amtType === 'field' && amtField) {
				var container = document.querySelector(CFG.containerId);
				var formEl = container ? container.closest('form') : null;
				if (formEl) {
					var amtInp = formEl.querySelector('[name="item_meta[' + amtField + ']"]');
					if (amtInp) baseAmount = parseFloat(amtInp.value) || 0;
				}
			}
			return baseAmount;
		}

		// -- Surcharge inline display (below card field) ---------------------
		function displaySurcharge(result, baseAmt) {
			var el = document.getElementById(CFG.surchargeId);
			if (!el) return;
			var amt = calcSurcharge(result, baseAmt || 0);
			if (amt > 0) {
				var s = result.surcharge;
				var label = CFG.surchargeLabel || 'Surcharge';
				var pctStr = (s && s.type === 'percent') ? ' (' + s.value + '%)' : '';
				el.textContent = label + ': $' + amt.toFixed(2) + pctStr;
				el.style.display = 'block';
			} else {
				el.style.display = 'none';
			}
		}

		function initSurchargeOnChange() {
			if (!CFG.showSurcharge || !_ht) return;
			_ht.on('change', function(data) {
				var cardVal = data && data.result ? (data.result.card || data.result.number || '') : '';
				if (String(cardVal).replace(/\D/g,'').length >= 6) {
					_ht.getSurcharge()
						.then(function(r) {
							log('getSurcharge() on change raw:', JSON.stringify(r));
							_surchargeAmount = calcSurcharge(r, _lastBaseAmount || 0);
							displaySurcharge(r, _lastBaseAmount || 0);
						})
						.catch(function(e) { logWarn('Surcharge on change failed:', e.message || e); });
				}
			});
		}

		// -- Init ------------------------------------------------------------
		function initCard() {
			log('Initialising | testMode:', CFG.testMode);

			if (typeof window.HostedTokenization === 'undefined') {
				logErr('window.HostedTokenization is not defined.');
				showError(CFG.i18n.loadFailed);
				return;
			}

			try {
				var ctorOpts = { target: CFG.containerId };
				if (CFG.styleObj && Object.keys(CFG.styleObj).length > 0) {
					ctorOpts.styles = CFG.styleObj;
				}
				// Paay 3DS: apiKey + challenge target in constructor threeDS option.
				// Reference: new HostedTokenization(sourceKey, { target: '#card-div', threeDS: { apiKey } })
				// The challenge iframe is rendered inside CFG.threeDsFrameId by the SDK.
				if (CFG.threeDsEnabled && CFG.paayApiKey) {
					// Per SDK reference:
					// new HostedTokenization(sourceKey, { target: '#card-div', threeDS: { apiKey } })
					// The SDK uses threeDS.apiKey to authenticate with Paay and renders
					// the ACS challenge iframe into CFG.threeDsFrameId (shown before verify3DS call).
					// The SDK reference shows: options = { target: '#card-div', threeDS: { apiKey } }
					// threeDS.target tells the SDK where to inject the ACS challenge iframe.
					// This MUST be set at construction so the SDK has the container reference
					// before verify3DS() is called.
					ctorOpts.threeDS = {
						apiKey : CFG.paayApiKey,
					};
					log3ds('INIT', {
						status           : 'Paay 3DS configured in constructor',
						apiKey           : CFG.paayApiKey.substring(0,8) + '...',
						cardTarget       : ctorOpts.target,
					});
				} else if (CFG.threeDsEnabled) {
					log3ds('INIT', 'Native 3DS (no Paay key) — challenge renders inside card iframe.');
				}
				_ht = new window.HostedTokenization(CFG.sourceKey, ctorOpts);

				log3ds('INIT', {
					status       : 'HostedTokenization instance created — waiting for iframe ready',
					enabled      : CFG.threeDsEnabled,
					frictionless : CFG.threeDsFrictionless,
					paay_key     : CFG.paayApiKey ? CFG.paayApiKey.substring(0,8)+'...' : '(none — native 3DS only)',
					mode         : CFG.threeDsEnabled ? (CFG.threeDsFrictionless ? 'frictionless' : 'standard') : 'disabled',
					browser_info : {
						screen      : (_threeDsBrowserInfo.screen_width||'?')+'x'+(_threeDsBrowserInfo.screen_height||'?'),
						language    : _threeDsBrowserInfo.language    || '?',
						tz_offset   : _threeDsBrowserInfo.timezone_offset,
						color_depth : _threeDsBrowserInfo.color_depth || '?',
					},
				});

				_ht.on('ready', function() {
					_ready = true;
					log('iFrame READY.');
					log3ds('READY', {
						status       : 'HostedTokenization iFrame ready',
						enabled      : CFG.threeDsEnabled,
						frictionless : CFG.threeDsFrictionless,
						paay         : CFG.paayEnabled,
						mode         : CFG.threeDsEnabled ? (CFG.threeDsFrictionless ? 'frictionless' : 'standard') : 'disabled',
					});
					if (CFG.styleObj && Object.keys(CFG.styleObj).length > 0) {
						_ht.setStyles(CFG.styleObj);
					}
					initSurchargeOnChange();
				});

				_ht.on('change', function(data) { log('change event:', data); _lastCardEvent = data; });
				_ht.on('input',  function(data) { log('input event:',  data); _lastCardEvent = data; });

				// ── 3DS challenge event ────────────────────────────────────────────────
				// Fired by the HostedTokenization SDK when the issuer requires a challenge.
				// When Paay is configured the challenge is handled server-side;
				// the overlay reassures the user while authentication completes.
				_ht.on('challenge', function(data) {
					if (!CFG.threeDsEnabled) {
						log3ds('DISABLED', '3DS off for this form — challenge event ignored.');
						return;
					}

					var challengeMode = CFG.threeDsFrictionless ? 'frictionless (issuer override)' : 'standard';
					hideLoader(); // challenge window is about to appear
					log3ds('CHALLENGE', {
						mode      : challengeMode,
						paay      : CFG.paayEnabled,
						raw_event : JSON.stringify(data),
					});

					// Scroll to top immediately so the SDK's challenge modal is visible
					window.scrollTo(0, 0);
					document.documentElement.scrollTop = 0;
					document.body.scrollTop = 0;
					log3ds('OVERLAY_SHOW', { mode: challengeMode, paay: CFG.paayEnabled, note: 'Scrolled to top' });
				});

				var container = document.querySelector(CFG.containerId);
				if (!container) { logErr('Container not found:', CFG.containerId); return; }

				var formEl = container.closest('form');
				if (!formEl) { logErr('Parent <form> not found.'); return; }

				log('Submit listener wired to form:', formEl.id || formEl);
				formEl.addEventListener('submit', handleSubmit);

			} catch(ex) {
				logErr('Exception during init:', ex);
				showError(ex.message || CFG.i18n.formError);
			}
		}

		// -- Script loader ---------------------------------------------------
		// Uses the module-level _sdkState / _sdkCallbacks so the external
		// Accept.Blue SDK is injected into the page exactly once, even when
		// multiple card fields share the same scriptUrl.
		//
		// The SDK at tokenization.accept.blue/tokenization/v0.3/ throws
		// "window.HostedTokenization is already defined" if it is executed
		// when that global already exists (it has no internal guard).  This
		// happens in three scenarios this function must handle:
		//
		//   A) Same-page multiple fields: two initCardField() calls race to
		//      inject the tag.  _sdkState prevents the second injection.
		//
		//   B) BFCache / soft navigation: the browser restores the page from
		//      cache.  Our IIFE re-runs (resetting _sdkState to 'idle') but
		//      window.HostedTokenization is still defined on the global.
		//      Without the window check below this would inject the tag again
		//      and trigger the fatal error.
		//
		//   C) Another plugin / theme already loaded the SDK via its own
		//      <script> tag before our IIFE ran.
		//
		// Priority: always test window.HostedTokenization FIRST, before any
		// state-machine check, so cases B and C are caught even when
		// _sdkState is 'idle' (fresh IIFE execution).
		function loadScript(url, cb) {
			log('Loading:', url);

			// ── Priority 0: global already defined (BFCache / external loader) ──
			if (typeof window.HostedTokenization !== 'undefined') {
				log('window.HostedTokenization already defined — skipping injection.');
				_sdkState = 'ready';
				cb();
				return;
			}

			// ── 1. Shared lock: SDK already loaded in this page lifecycle ───────
			if (_sdkState === 'ready') {
				log('SDK already ready (shared lock).');
				cb();
				return;
			}

			// ── 2. Shared lock: another field is loading the SDK right now ──────
			if (_sdkState === 'loading') {
				logWarn('SDK load already in progress (shared lock) — queuing callback.');
				_sdkCallbacks.push(cb);
				return;
			}

			// ── 3. Script tag already in DOM but SDK not yet defined ─────────────
			//    (race: tag injected by a very recent call before state was set)
			if (document.querySelector('script[src="' + url + '"]')) {
				logWarn('Script tag already in DOM — polling for HostedTokenization.');
				_sdkState = 'loading';
				_sdkCallbacks.push(cb);
				var t = setInterval(function() {
					if (typeof window.HostedTokenization !== 'undefined') {
						clearInterval(t);
						_sdkState = 'ready';
						var pending = _sdkCallbacks.splice(0);
						pending.forEach(function(fn) { fn(); });
					}
				}, 60);
				return;
			}

			// ── 4. First caller: inject the script exactly once ──────────────────
			_sdkState = 'loading';
			_sdkCallbacks.push(cb);
			var s     = document.createElement('script');
			s.src     = url;
			s.async   = true;
			s.onload  = function() {
				log('Script loaded.');
				_sdkState = 'ready';
				var pending = _sdkCallbacks.splice(0);
				pending.forEach(function(fn) { fn(); });
			};
			s.onerror = function() {
				logErr('Script FAILED to load. Ad blocker?');
				_sdkState = 'idle'; // allow retry
				var pending = _sdkCallbacks.splice(0);
				pending.forEach(function() { showError(CFG.i18n.scriptBlocked); });
			};
			document.head.appendChild(s);
		}

		// -- Boot ------------------------------------------------------------
		// Note: loadScript() already handles the case where window.HostedTokenization
		// is defined before the tag is injected (Priority 0 check), so we do not
		// need a separate pre-check here.  We call loadScript() unconditionally and
		// let it short-circuit as appropriate.
		//
		// CFG.sdkUrl (not 'scriptUrl') is used intentionally — Formidable Forms v1.16+
		// scans wp_localize_script data for a key named 'scriptUrl' and calls
		// wp_enqueue_style() with its value, treating it as a payment-gateway stylesheet.
		// Renaming the key prevents that interception.
		log('readyState:', document.readyState);
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function() { loadScript(CFG.sdkUrl, initCard); });
		} else {
			loadScript(CFG.sdkUrl, initCard);
		}

	} // end initCardField

} )();
