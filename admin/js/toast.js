/*
 * toast.js — global HTML notification module for the admin panel.
 *
 * Classic (non-module) script. Loaded before every other admin script so the
 * globals it defines are available to inline <script> blocks AND ES modules.
 *
 * Public API (all attached to window):
 *   notify(message, type)             -> shows a dismissible auto-hiding toast.
 *                                        type: 'success' | 'error' | 'info' (default 'info')
 *   confirmDialog(message, opts)      -> Promise<boolean>  (OK = true, Cancel/overlay/Esc = false)
 *   promptDialog(message, def, opts)  -> Promise<string|null>  (OK = input value, Cancel/overlay/Esc = null)
 *
 * `opts` (optional) for confirm/prompt: { okText, cancelText }.
 *
 * Fully self-contained: injects its own <style> once and creates its own toast
 * container lazily on first use. No template edits or external dependencies
 * (does NOT rely on jquery.modal, so it also works on login.php).
 */
(function () {
    'use strict';

    if (window.__ytToastLoaded) return;
    window.__ytToastLoaded = true;

    var STYLE_ID = 'yt-toast-styles';
    var CONTAINER_ID = 'yt-toast-container';

    var CSS = [
        '.yt-toast-container{',
        '  position:fixed;top:22px;left:50%;transform:translateX(-50%);',
        '  display:flex;flex-direction:column;align-items:center;gap:10px;',
        '  z-index:2147483000;pointer-events:none;max-width:92vw;',
        '}',
        '.yt-toast{',
        '  padding:12px 18px;border-radius:999px;background:rgba(13,24,46,0.96);',
        '  color:#f4f8ff;font-size:14px;font-weight:700;letter-spacing:0.04em;',
        '  font-family:inherit;box-shadow:0 12px 30px rgba(0,0,0,0.28);',
        '  opacity:0;transform:translateY(-18px);',
        '  transition:opacity 0.18s ease, transform 0.18s ease;',
        '  pointer-events:auto;cursor:pointer;max-width:100%;',
        '  text-align:center;white-space:pre-line;word-break:break-word;',
        '}',
        '.yt-toast.is-visible{opacity:1;transform:translateY(0);}',
        '.yt-toast.is-success{background:rgba(12,76,46,0.96);}',
        '.yt-toast.is-error{background:rgba(128,28,40,0.96);}',
        '.yt-toast.is-info{background:rgba(13,24,46,0.96);}',
        '.yt-modal-overlay{',
        '  position:fixed;inset:0;background:rgba(4,10,22,0.72);',
        '  display:flex;align-items:center;justify-content:center;padding:16px;',
        '  z-index:2147483600;opacity:0;transition:opacity 0.15s ease;',
        '}',
        '.yt-modal-overlay.is-visible{opacity:1;}',
        '.yt-modal{',
        '  background:#0d182e;color:#f4f8ff;border-radius:12px;padding:22px 24px;',
        '  min-width:300px;max-width:90vw;box-sizing:border-box;',
        '  box-shadow:0 20px 60px rgba(0,0,0,0.5);font-family:inherit;',
        '  border:1px solid rgba(119,178,255,0.18);',
        '  transform:translateY(-8px);transition:transform 0.15s ease;',
        '}',
        '.yt-modal-overlay.is-visible .yt-modal{transform:translateY(0);}',
        '.yt-modal-message{',
        '  font-size:15px;line-height:1.5;margin-bottom:18px;',
        '  white-space:pre-wrap;word-break:break-word;',
        '}',
        '.yt-modal-input{',
        '  width:100%;box-sizing:border-box;padding:9px 12px;margin-bottom:18px;',
        '  border-radius:8px;border:1px solid rgba(119,178,255,0.35);',
        '  background:rgba(255,255,255,0.06);color:#f4f8ff;font-size:14px;',
        '  font-family:inherit;',
        '}',
        '.yt-modal-input:focus{outline:none;border-color:#77b2ff;}',
        '.yt-modal-actions{display:flex;justify-content:flex-end;gap:10px;}',
        '.yt-modal-btn{',
        '  padding:8px 18px;border-radius:8px;border:none;font-size:14px;',
        '  font-weight:700;letter-spacing:0.02em;cursor:pointer;font-family:inherit;',
        '}',
        '.yt-modal-btn-cancel{background:rgba(255,255,255,0.12);color:#dfe8f7;}',
        '.yt-modal-btn-cancel:hover{background:rgba(255,255,255,0.2);}',
        '.yt-modal-btn-ok{background:#2f6be0;color:#fff;}',
        '.yt-modal-btn-ok:hover{background:#3a78f0;}'
    ].join('\n');

    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = CSS;
        (document.head || document.documentElement).appendChild(style);
    }

    function getContainer() {
        var c = document.getElementById(CONTAINER_ID);
        if (!c) {
            c = document.createElement('div');
            c.id = CONTAINER_ID;
            c.className = 'yt-toast-container';
            (document.body || document.documentElement).appendChild(c);
        }
        return c;
    }

    function notify(message, type) {
        injectStyles();
        var container = getContainer();
        var toast = document.createElement('div');
        var cls = 'yt-toast';
        if (type === 'success') cls += ' is-success';
        else if (type === 'error') cls += ' is-error';
        else cls += ' is-info';
        toast.className = cls;
        toast.textContent = message == null ? '' : String(message);
        container.appendChild(toast);

        var hideTimer = null;
        var remove = function () {
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            toast.classList.remove('is-visible');
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 220);
        };

        requestAnimationFrame(function () {
            requestAnimationFrame(function () { toast.classList.add('is-visible'); });
        });

        toast.addEventListener('click', remove);
        hideTimer = setTimeout(remove, 2500);
        return toast;
    }

    function openModal(opts) {
        injectStyles();
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'yt-modal-overlay';

            var modal = document.createElement('div');
            modal.className = 'yt-modal';

            var msg = document.createElement('div');
            msg.className = 'yt-modal-message';
            msg.textContent = opts.message == null ? '' : String(opts.message);
            modal.appendChild(msg);

            var input = null;
            if (opts.isPrompt) {
                input = document.createElement('input');
                input.type = 'text';
                input.className = 'yt-modal-input';
                input.value = opts.defaultValue == null ? '' : String(opts.defaultValue);
                modal.appendChild(input);
            }

            var actions = document.createElement('div');
            actions.className = 'yt-modal-actions';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'yt-modal-btn yt-modal-btn-cancel';
            cancelBtn.textContent = opts.cancelText || 'Cancel';

            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'yt-modal-btn yt-modal-btn-ok';
            okBtn.textContent = opts.okText || 'OK';

            actions.appendChild(cancelBtn);
            actions.appendChild(okBtn);
            modal.appendChild(actions);
            overlay.appendChild(modal);
            (document.body || document.documentElement).appendChild(overlay);

            requestAnimationFrame(function () {
                requestAnimationFrame(function () { overlay.classList.add('is-visible'); });
            });

            var settled = false;
            function cleanup() {
                document.removeEventListener('keydown', onKey, true);
                overlay.classList.remove('is-visible');
                setTimeout(function () {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                }, 180);
            }
            function done(value) {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(value);
            }
            function onCancel() { done(opts.isPrompt ? null : false); }
            function onOk() { done(opts.isPrompt ? (input ? input.value : '') : true); }
            function onKey(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    onCancel();
                } else if (e.key === 'Enter') {
                    if (!opts.isPrompt || document.activeElement === input) {
                        e.preventDefault();
                        onOk();
                    }
                }
            }

            cancelBtn.addEventListener('click', onCancel);
            okBtn.addEventListener('click', onOk);
            overlay.addEventListener('mousedown', function (e) {
                if (e.target === overlay) onCancel();
            });
            document.addEventListener('keydown', onKey, true);

            setTimeout(function () {
                if (input) { input.focus(); input.select(); }
                else { okBtn.focus(); }
            }, 40);
        });
    }

    window.notify = notify;
    window.confirmDialog = function (message, opts) {
        opts = opts || {};
        return openModal({
            message: message,
            isPrompt: false,
            okText: opts.okText,
            cancelText: opts.cancelText
        });
    };
    window.promptDialog = function (message, defaultValue, opts) {
        opts = opts || {};
        return openModal({
            message: message,
            isPrompt: true,
            defaultValue: defaultValue,
            okText: opts.okText,
            cancelText: opts.cancelText
        });
    };
})();
