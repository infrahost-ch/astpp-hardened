/*
 * ASTPP fork (audit O4): event handlers without inline JavaScript.
 *
 * A Content-Security-Policy without 'unsafe-inline' for scripts blocks onclick="..." and every
 * other event handler attribute. The views use data-astpp-<event>="<handler>" instead (click,
 * dblclick, change, input, keyup, keydown, keypress, submit, mouseover, mouseout, focus, blur,
 * load); this file runs them by delegation from the document, also for content loaded later
 * (popups, grids). The handler text is NOT evaluated: it is parsed as a small language -
 *
 *   handler    statements separated by ";"
 *   statement  [return] call | return false | return true
 *   call       name(args) - name from CALLS below (global functions of ASTPP), or one of
 *              this.form.submit(), window.history.back(), history.back(), confirm(), alert()
 *   args       'text', "text", numbers, true, false, null, undefined, this, this.value,
 *              this.checked, this.id, this.name, event, or a bare name: the element with this id
 *              (as the old inline handler saw it through the named access of the window)
 *   A leading "javascript:" (a label in the old handlers) is ignored.
 *
 * As with the inline handler, "return <false>" prevents the default action; a link with href="#"
 * (formerly href="javascript:void(0)") never navigates. Listeners run in the capture phase, so a
 * stopPropagation() of a grid or popup cannot swallow them. A handler that does not parse or names
 * a function outside CALLS does nothing and is reported in the browser console.
 *
 * New functions for handlers: add the name to CALLS. Tool: astpp-debian13/tools/csp-convert.php
 * turns old handlers into data-astpp-*; astpp-debian13/tools/csp-scan.php lists what is left.
 */
(function () {
	'use strict';

	var CALLS = [
		'account_change_add', 'add_package_destination', 'add_package_pattern', 'add_product_data',
		'addMoreRows', 'addincallerRows', 'addoutcallerRows', 'addterminateoutcallertranslation',
		'addterminatetranslation', 'admin_pay', 'calculate', 'calltype_change', 'cancel', 'check_btn',
		'check_function', 'check_validation',
		'clickchkbox', 'delete_multiple', 'edit_package_destination', 'FocusOnInput', 'form_confirm',
		'form_submit', 'get_alert_message', 'get_alert_msg', 'get_alert_msg_destination',
		'get_alert_msg_restore', 'get_destination', 'get_reliase_msg', 'hidepassword', 'image_delete',
		'install_addon', 'invoice_delete', 'isNumberKey', 'load_product_prefixes', 'login_type_change',
		'myFunction', 'open_menu',
		'open_sub_menu', 'pauseAudio', 'payment', 'playAudio', 'process_alert', 'process_email',
		'process_sms', 'processForm', 'redirect_page', 'reload_port', 'remove_save_speed_dial',
		'removeRows', 'removeincaller', 'removeoutcaller', 'removeterminateoutcallertranslation',
		'removeterminatetranslation', 'save_speed_dial', 'search_btn', 'seetext', 'showdiv', 'test',
		'trunk_change', 'validate_form', 'validate_setting', 'validateForm'
	];
	var BUILTIN = {
		'confirm': function (args) { return window.confirm.apply(window, args); },
		'alert': function (args) { return window.alert.apply(window, args); },
		'window.history.back': function () { window.history.back(); },
		'history.back': function () { window.history.back(); },
		'this.form.submit': function (args, el) { el.form.submit(); }
	};
	// attribute event -> DOM event for the delegation (focus/blur do not bubble, focusin/out do)
	var EVENTS = {
		click: 'click', dblclick: 'dblclick', change: 'change', input: 'input', keyup: 'keyup',
		keydown: 'keydown', keypress: 'keypress', submit: 'submit', mouseover: 'mouseover',
		mouseout: 'mouseout', focus: 'focusin', blur: 'focusout'
	};
	var OWN_ONLY = { focus: true, blur: true }; // the old onfocus/onblur fired for the element itself only

	var allowed = {};
	var i;
	for (i = 0; i < CALLS.length; i++) {
		allowed[CALLS[i]] = true;
	}
	var cache = {};

	function report(src, why) {
		if (window.console && console.error) {
			console.error('astpp_csp: handler not run (' + why + '): ' + src);
		}
	}

	function tokenize(s) {
		var out = [], p = 0, c, m, q, v, rest;
		while (p < s.length) {
			c = s.charAt(p);
			if (/\s/.test(c)) {
				p++;
				continue;
			}
			if (c === "'" || c === '"') {
				q = c;
				v = '';
				p++;
				while (p < s.length && s.charAt(p) !== q) {
					if (s.charAt(p) === '\\' && p + 1 < s.length) {
						c = s.charAt(p + 1);
						v += c === 'n' ? '\n' : (c === 't' ? '\t' : (c === 'r' ? '\r' : c));
						p += 2;
						continue;
					}
					v += s.charAt(p++);
				}
				if (p >= s.length) {
					throw 'unterminated string';
				}
				p++;
				out.push({ k: 'str', v: v });
				continue;
			}
			rest = s.slice(p);
			m = /^-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/.exec(rest);
			if (m && (c === '-' || c === '.' || /\d/.test(c))) {
				out.push({ k: 'num', v: parseFloat(m[0]) });
				p += m[0].length;
				continue;
			}
			m = /^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*/.exec(rest);
			if (m) {
				out.push({ k: 'id', v: m[0] });
				p += m[0].length;
				continue;
			}
			if ('(),;:'.indexOf(c) >= 0) {
				out.push({ k: 'p', v: c });
				p++;
				continue;
			}
			throw 'unexpected "' + c + '"';
		}
		return out;
	}

	function parse(src) {
		var t = tokenize(src), p = 0, prog = [], st, x;
		function is(k, v) {
			var y = t[p];
			return !!y && y.k === k && (v === undefined || y.v === v);
		}
		function eat(k, v) {
			if (!is(k, v)) {
				throw 'expected ' + (v || k);
			}
			return t[p++];
		}
		function label() {
			if (is('id', 'javascript') && t[p + 1] && t[p + 1].k === 'p' && t[p + 1].v === ':') {
				p += 2;
			}
		}
		function arg() {
			var y = t[p++];
			if (!y) {
				throw 'argument missing';
			}
			if (y.k === 'str' || y.k === 'num') {
				return { lit: y.v };
			}
			if (y.k === 'id') {
				switch (y.v) {
				case 'true': return { lit: true };
				case 'false': return { lit: false };
				case 'null': return { lit: null };
				case 'undefined': return { lit: undefined };
				case 'this': case 'this.value': case 'this.checked': case 'this.id': case 'this.name': case 'event':
					return { ref: y.v };
				}
				if (/^[A-Za-z_$][\w$]*$/.test(y.v)) {
					return { el: y.v };
				}
			}
			throw 'argument "' + y.v + '" not allowed';
		}
		while (p < t.length) {
			if (is('p', ';')) {
				p++;
				continue;
			}
			label();
			st = { ret: false };
			if (is('id', 'return')) {
				st.ret = true;
				p++;
			}
			if (st.ret && (is('id', 'false') || is('id', 'true'))) {
				st.value = t[p++].v === 'true';
			} else if (st.ret && (is('p', ';') || p >= t.length)) {
				st.value = undefined;
			} else {
				x = eat('id').v;
				if (!allowed[x] && !BUILTIN[x]) {
					throw 'function ' + x + ' not in CALLS';
				}
				st.call = x;
				st.args = [];
				eat('p', '(');
				if (!is('p', ')')) {
					st.args.push(arg());
					while (is('p', ',')) {
						p++;
						st.args.push(arg());
					}
				}
				eat('p', ')');
			}
			prog.push(st);
			if (p < t.length) {
				eat('p', ';');
			}
		}
		return prog;
	}

	function value(a, el, ev) {
		if ('lit' in a) {
			return a.lit;
		}
		if (a.el) {
			return document.getElementById(a.el);
		}
		switch (a.ref) {
		case 'this': return el;
		case 'this.value': return el.value;
		case 'this.checked': return el.checked;
		case 'this.id': return el.id;
		case 'this.name': return el.name;
		case 'event': return ev;
		}
		return undefined;
	}

	/** Runs one handler like the inline attribute did; false = prevent the default action. */
	function run(el, ev, src) {
		var prog, k, st, args, j, result;
		if (!cache.hasOwnProperty(src)) {
			try {
				cache[src] = parse(src);
			} catch (e) {
				cache[src] = null;
				report(src, e);
			}
		}
		prog = cache[src];
		if (!prog) {
			return;
		}
		try {
			for (k = 0; k < prog.length; k++) {
				st = prog[k];
				if (!st.call) {
					result = st.value;
					break;
				}
				args = [];
				for (j = 0; j < st.args.length; j++) {
					args.push(value(st.args[j], el, ev));
				}
				if (BUILTIN[st.call]) {
					result = BUILTIN[st.call](args, el);
				} else if (typeof window[st.call] === 'function') {
					result = window[st.call].apply(window, args);
				} else {
					report(src, 'function ' + st.call + ' not defined on this page');
					return;
				}
				if (st.ret) {
					break;
				}
				result = undefined;
			}
		} catch (e) {
			// as an inline handler: the error is reported, the default action is not prevented
			if (window.console && console.error) {
				console.error(e);
			}
			return;
		}
		if (result === false) {
			ev.preventDefault();
		}
	}

	function dispatch(ev) {
		var name = ev.type === 'focusin' ? 'focus' : (ev.type === 'focusout' ? 'blur' : ev.type);
		var attr = 'data-astpp-' + name;
		var node = ev.target;
		var src;
		if (node && node.nodeType !== 1) {
			node = node.parentNode;
		}
		while (node && node.nodeType === 1) {
			src = node.getAttribute(attr);
			if (src !== null && (!OWN_ONLY[name] || node === ev.target)) {
				run(node, ev, src);
				if (name === 'click' && node.tagName === 'A' && node.getAttribute('href') === '#') {
					ev.preventDefault(); // was href="javascript:void(0)"
				}
			}
			node = node.parentNode;
		}
	}

	var type;
	for (type in EVENTS) {
		if (EVENTS.hasOwnProperty(type)) {
			document.addEventListener(EVENTS[type], dispatch, true);
		}
	}

	// data-astpp-load (formerly <body onload>): after the page has loaded
	function loaded(ev) {
		var list = document.querySelectorAll('[data-astpp-load]');
		var k;
		for (k = 0; k < list.length; k++) {
			run(list[k], ev, list[k].getAttribute('data-astpp-load'));
		}
	}
	if (document.readyState === 'complete') {
		loaded({ type: 'load', preventDefault: function () {} });
	} else {
		window.addEventListener('load', loaded);
	}

	// for tests and the scan tool: parse a handler text, throws on errors
	window.astpp_csp_parse = parse;
}());
