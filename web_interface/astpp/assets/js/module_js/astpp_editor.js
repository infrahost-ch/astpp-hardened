/*
 * ASTPP fork: rich text editor - TinyMCE 8 (GPL-2.0-or-later), self-hosted.
 * Replaces CKEditor 4.11 (end of life, known XSS issues), TinyMCE 4.3 and markItUp.
 *
 *   astppEditor(selector, options)   options are passed to tinymce.init()
 */
(function (window, $) {
	'use strict';
	window.astppEditor = function (selector, options) {
		options = options || {};
		var userSetup = options.setup;
		var cfg = $.extend({
			selector: selector,
			license_key: 'gpl',
			base_url: (window.base_url || '/') + 'assets/vendor/tinymce-8.9.3',
			suffix: '.min',
			promotion: false,
			branding: false,
			menubar: false,
			height: 350,
			plugins: 'lists advlist link autolink table code image charmap searchreplace fullscreen',
			toolbar: 'undo redo | blocks | bold italic underline forecolor | alignleft aligncenter alignright | bullist numlist | link image table | code fullscreen',
			// templates contain placeholders like #NAME# and absolute links - keep them untouched
			convert_urls: false,
			relative_urls: false,
			entity_encoding: 'raw'
		}, options);
		cfg.setup = function (editor) {
			// keep the textarea in sync: some forms are submitted by script, not by a submit event
			editor.on('change input undo redo', function () { editor.save(); });
			if (userSetup) {
				userSetup(editor);
			}
		};
		return window.tinymce.init(cfg);
	};
})(window, jQuery);
