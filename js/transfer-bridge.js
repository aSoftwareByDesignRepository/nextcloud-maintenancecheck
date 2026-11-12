/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.MaintenanceCheckApi / MaintenanceCheckMessaging
 * contract the synced js/transfer.js expects, in MaintenanceCheck's own
 * API envelope ({success:true,data} / {success:false,error,message,fields}).
 * App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.MaintenanceCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/maintenancecheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// Messaging: the synced transfer.js asks for .alert/.polite — map both
	// onto the app's own toast channel (visible + deduped + live-region
	// announce built in) and mirror into the transfer live region the
	// synced part renders. OC.Notification is NOT a visible channel:
	// showTemporary was removed from NC>=31 bundles, so the guard never
	// fires and feedback stays invisible to sighted users
	// (dead_platform_toast_api / bc-vis-transfer-messaging-invisible-on-nc35).
	const msg = window.MaintenanceCheckMessaging || {};
	function live(text) {
		const el = document.getElementById('mn-transfer-result-live');
		if (el) el.textContent = text;
	}
	function announce(text, kind) {
		const dom = window.MnApp && window.MnApp.__dom;
		if (dom && typeof dom.toast === 'function') {
			dom.toast(text, kind);
			return;
		}
		if (window.OC && OC.Notification && OC.Notification.showTemporary) {
			OC.Notification.showTemporary(text);
		}
	}
	msg.alert = function (text) { announce(text, 'error'); live(text); };
	msg.polite = function (text) { announce(text, 'success'); live(text); };
	window.MaintenanceCheckMessaging = msg;
})();
