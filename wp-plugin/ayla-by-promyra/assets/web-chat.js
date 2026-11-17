(function () {
    // Storage can be blocked (private mode, strict browser settings) and then every access throws.
    var store = {
        get: function (key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
        set: function (key, value) { try { localStorage.setItem(key, value); } catch (e) { /* the chat still works for this page */ } },
        remove: function (key) { try { localStorage.removeItem(key); } catch (e) { /* nothing to remove */ } }
    };

    // Each visitor needs their own id: a shared one would put different people into the same conversation.
    function newId() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        var bytes = new Uint8Array(16);
        if (window.crypto && crypto.getRandomValues) { crypto.getRandomValues(bytes); }
        else { for (var i = 0; i < 16; i++) { bytes[i] = Math.floor(Math.random() * 256); } }
        bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    var ICONS = {
        expand: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>',
        shrink: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/></svg>',
        close: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>'
    };

    if (store.get('aylaChatFlowVersion') !== '3') {
        store.remove('aylaVisitorId');
        store.remove('aylaConversationId');
        store.remove('aylaVisitorProfileComplete');
        store.remove('aylaVisitorDetailsSaved');
        store.set('aylaChatFlowVersion', '3');
    }

    var copy = AylaWebChat.i18n || {};
    var state = {
        visitorId: store.get('aylaVisitorId'),
        conversationId: store.get('aylaConversationId'),
        lang: copy[AylaWebChat.lang] ? AylaWebChat.lang : 'en',
        large: false,
        chatHere: false
    };
    if (!state.visitorId) {
        state.visitorId = newId();
        store.set('aylaVisitorId', state.visitorId);
    }

    // Every label follows the language the visitor is writing in, not the site language.
    function L(key) { return (copy[state.lang] && copy[state.lang][key]) || (copy.en && copy.en[key]) || ''; }

    function detect(text) {
        if (/[\u0600-\u06FF]/.test(text)) { return 'ar'; }
        if (/[A-Za-z]/.test(text)) { return 'en'; }
        return null;
    }

    var root = document.createElement('section');
    root.className = 'ayla-chat';
    root.innerHTML = '<button class="ayla-chat__toggle" type="button" aria-expanded="false"><img src="' + AylaWebChat.logoUrl + '" alt=""><span class="ayla-chat__pulse"></span></button>' +
        '<div class="ayla-chat__panel" role="dialog" aria-modal="false" hidden>' +
        '<div class="ayla-chat__head">' +
        '<span class="ayla-chat__avatar"><img src="' + AylaWebChat.logoUrl + '" alt=""><span class="ayla-chat__dot"></span></span>' +
        '<div class="ayla-chat__id"><h2 class="ayla-chat__title"></h2><span class="ayla-chat__status"></span></div>' +
        '<button class="ayla-chat__icon" type="button" data-size-toggle></button>' +
        '<button class="ayla-chat__icon ayla-chat__icon--close" type="button" data-close>' + ICONS.close + '</button>' +
        '</div>' +
        '<div class="ayla-chat__messages" aria-live="polite"></div>' +
        '<form class="ayla-chat__form"><label class="ayla-chat__label" for="ayla-chat-input"></label>' +
        '<div class="ayla-chat__row"><input id="ayla-chat-input" name="message" maxlength="2000" required dir="auto" autocomplete="off">' +
        '<button type="submit" class="ayla-chat__send"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.4 20.4l17.8-7.6c.9-.4.9-1.7 0-2.1L3.4 3.1c-.7-.3-1.5.2-1.5 1l0 4.7c0 .5.4.9.9 1l11.2 2.2L2.8 14.2c-.5.1-.9.5-.9 1l0 4.2c0 .8.8 1.3 1.5 1z"></path></svg></button></div></form>' +
        '<small class="ayla-chat__brand"><a href="https://promyra.ae" target="_blank" rel="noopener noreferrer">Ayla by Promyra Digital Solutions</a></small></div>';
    document.body.appendChild(root);

    var toggle = root.querySelector('.ayla-chat__toggle'), panel = root.querySelector('.ayla-chat__panel'), messages = root.querySelector('.ayla-chat__messages'), form = root.querySelector('form');
    var input = form.elements.message, submit = form.querySelector('button[type=submit]'), sizeButton = root.querySelector('[data-size-toggle]'), closeButton = root.querySelector('[data-close]');
    var lastWhatsApp = null;
    var whatsappUrl = AylaWebChat.whatsappUrl || null;
    var chooser = null;

    // Reads the host page's own styles (buttons, links, fonts) so the widget matches the site exactly.
    function adoptTheme() {
        var probe = document.createElement('div');
        probe.style.cssText = 'position:absolute!important;left:-9999px;top:-9999px;visibility:hidden;pointer-events:none;';
        var link = document.createElement('a'); link.href = '#'; link.textContent = 'a';
        var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'wp-block-button__link wp-element-button button btn'; btn.textContent = 'b';
        probe.appendChild(link); probe.appendChild(btn);
        document.body.appendChild(probe);

        function parse(c) {
            var m = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+%?))?\s*\)/.exec(c || '');
            if (!m) { return null; }
            var a = m[4] === undefined ? 1 : parseFloat(m[4]) / (String(m[4]).indexOf('%') > -1 ? 100 : 1);
            return { r: +m[1], g: +m[2], b: +m[3], a: a };
        }
        function luma(c) { return (0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b) / 255; }
        function css(c) { return 'rgb(' + Math.round(c.r) + ',' + Math.round(c.g) + ',' + Math.round(c.b) + ')'; }
        function usable(c) { return c && c.a > 0.5 && luma(c) < 0.9; }

        var bodyStyle = getComputedStyle(document.body);
        var btnStyle = getComputedStyle(btn);
        var accent = parse(btnStyle.backgroundColor);
        if (!usable(accent)) { accent = parse(getComputedStyle(link).color); }
        var radius = parseFloat(btnStyle.borderRadius);
        probe.parentNode.removeChild(probe);

        if (usable(accent)) {
            root.style.setProperty('--ayla-accent', css(accent));
            root.style.setProperty('--ayla-on-accent', luma(accent) > 0.62 ? '#17212f' : '#ffffff');
        }
        var text = parse(bodyStyle.color);
        if (text && text.a > 0.5 && luma(text) < 0.55) { root.style.setProperty('--ayla-text', css(text)); }
        if (bodyStyle.fontFamily) { root.style.setProperty('--ayla-font', bodyStyle.fontFamily); }
        if (!isNaN(radius)) { root.style.setProperty('--ayla-radius', Math.max(6, Math.min(radius, 18)) + 'px'); }
    }
    adoptTheme();

    function applyLanguage() {
        root.querySelector('.ayla-chat__title').textContent = L('title');
        root.querySelector('.ayla-chat__status').textContent = L('online');
        root.querySelector('.ayla-chat__label').textContent = L('messageLabel');
        input.placeholder = L('messagePlaceholder');
        submit.setAttribute('aria-label', L('send'));
        toggle.setAttribute('aria-label', L('title'));
        panel.setAttribute('aria-label', L('title'));
        sizeButton.setAttribute('aria-label', state.large ? L('shrink') : L('expand'));
        sizeButton.setAttribute('title', state.large ? L('shrink') : L('expand'));
        closeButton.setAttribute('aria-label', L('close'));
        closeButton.setAttribute('title', L('close'));
        panel.setAttribute('dir', state.lang === 'ar' ? 'rtl' : 'ltr');
    }

    function setLanguage(lang) {
        if (lang && lang !== state.lang && copy[lang]) { state.lang = lang; applyLanguage(); }
    }
    sizeButton.innerHTML = ICONS.expand;
    applyLanguage();

    function setOpen(open) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        root.classList.toggle('ayla-chat--open', open);
        if (open) {
            showChooser();
            // With the channel choice on screen, do not pop the keyboard up before the visitor chooses.
            if (!chooser) { input.focus(); }
        } else {
            toggle.focus();
        }
    }

    toggle.addEventListener('click', function () { setOpen(panel.hidden); });
    closeButton.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) { setOpen(false); }
    });
    sizeButton.addEventListener('click', function () {
        state.large = root.classList.toggle('ayla-chat--large');
        sizeButton.innerHTML = state.large ? ICONS.shrink : ICONS.expand;
        applyLanguage();
        messages.scrollTop = messages.scrollHeight;
    });
    input.addEventListener('input', function () { setLanguage(detect(input.value)); });

    function inline(parent, text) {
        text.split(/(\*\*[^*]+\*\*)/).forEach(function (part) {
            if (/^\*\*[^*]+\*\*$/.test(part)) { var strong = document.createElement('strong'); strong.textContent = part.slice(2, -2); parent.appendChild(strong); }
            else if (part) { parent.appendChild(document.createTextNode(part)); }
        });
    }

    // Builds the message from plain text with DOM nodes only, so site visitors and the model can never inject HTML.
    function fill(el, text, interactive) {
        var list = null;
        String(text).split('\n').forEach(function (line) {
            var choice = /^\s*\d+[).]\s+(.+)$/.exec(line);
            var bullet = /^\s*[\u2022*-]\s+(.+)$/.exec(line);
            if (choice && interactive) {
                list = null;
                var button = document.createElement('button');
                button.type = 'button'; button.className = 'ayla-chat__choice';
                inline(button, choice[1]);
                button.addEventListener('click', function () { if (!submit.disabled) { send(choice[1].replace(/\*\*/g, '')); } });
                el.appendChild(button);
                return;
            }
            if (bullet || choice) {
                if (!list) { list = document.createElement('ul'); list.className = 'ayla-chat__list'; el.appendChild(list); }
                var li = document.createElement('li'); inline(li, (bullet || choice)[1]); list.appendChild(li);
                return;
            }
            list = null;
            if (!line.trim()) { return; }
            var row = document.createElement('div'); row.className = 'ayla-chat__line'; inline(row, line); el.appendChild(row);
        });
    }

    function bubble(direction, text, options) {
        var el = document.createElement('div');
        el.className = 'ayla-chat__message ayla-chat__message--' + direction + (options && options.kind ? ' ayla-chat__message--' + options.kind : '');
        el.setAttribute('dir', 'auto');
        if (options && options.typing) { el.classList.add('ayla-chat__message--typing'); el.innerHTML = '<span></span><span></span><span></span>'; }
        else { fill(el, text, options && options.interactive); }
        messages.appendChild(el);
        messages.scrollTop = messages.scrollHeight;
        return el;
    }

    function render(items, whatsappUrl) {
        messages.innerHTML = '';
        chooser = null;
        items.forEach(function (item, index) { bubble(item.direction, item.body, { interactive: item.direction === 'out' && index === items.length - 1 }); });
        lastWhatsApp = whatsappUrl || lastWhatsApp;
        if (lastWhatsApp) { var link = document.createElement('a'); link.className = 'ayla-chat__choice ayla-chat__choice--whatsapp'; link.href = lastWhatsApp; link.target = '_blank'; link.rel = 'noopener'; link.textContent = L('whatsapp'); messages.appendChild(link); }
        messages.scrollTop = messages.scrollHeight;
    }

    function hideChooser() { if (chooser) { chooser.remove(); chooser = null; } }

    // Offered once per page load when the panel opens on an empty chat.
    function showChooser() {
        if (chooser || !whatsappUrl || state.chatHere || messages.childElementCount) { return; }
        chooser = document.createElement('div');
        chooser.className = 'ayla-chat__chooser';

        var title = document.createElement('div');
        title.className = 'ayla-chat__message ayla-chat__message--prompt';
        title.textContent = L('chooseTitle');

        var whatsapp = document.createElement('a');
        whatsapp.className = 'ayla-chat__choice ayla-chat__choice--whatsapp';
        whatsapp.href = whatsappUrl; whatsapp.target = '_blank'; whatsapp.rel = 'noopener';
        whatsapp.textContent = L('whatsapp');

        var chat = document.createElement('button');
        chat.type = 'button'; chat.className = 'ayla-chat__choice';
        chat.textContent = L('chatHere');
        chat.addEventListener('click', function () { state.chatHere = true; hideChooser(); input.focus(); });

        chooser.appendChild(title); chooser.appendChild(whatsapp); chooser.appendChild(chat);
        messages.appendChild(chooser);
        messages.scrollTop = messages.scrollHeight;
    }

    function request(path, payload) {
        var data = new FormData();
        data.append('action', 'ayla_web_chat'); data.append('nonce', AylaWebChat.nonce); data.append('method', 'POST'); data.append('path', path);
        Object.keys(payload).forEach(function (key) { data.append(key, payload[key]); });

        // The server may take up to about a minute; after that the visitor gets the error message instead of waiting for ever.
        var controller = window.AbortController ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, 75000) : null;
        return fetch(AylaWebChat.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data, signal: controller ? controller.signal : undefined })
            .then(function (response) { return response.json(); })
            .finally(function () { if (timer) { clearTimeout(timer); } });
    }

    function send(value) {
        value = String(value).trim();
        if (!value) { return; }
        setLanguage(detect(value));
        hideChooser();
        state.chatHere = true;
        submit.disabled = true;
        bubble('in', value);
        var typing = bubble('out', '', { typing: true });
        input.value = '';

        request('/web-chat/conversations/' + (state.conversationId || 0) + '/messages', { visitor_id: state.visitorId, message: value, conversation_id: state.conversationId || '' }).then(function (response) {
            if (!response.success) { throw new Error('failed'); }
            state.conversationId = response.data.conversation_id; store.set('aylaConversationId', state.conversationId);
            render(response.data.messages, response.data.whatsapp_url);
        }).catch(function () {
            if (typing.parentNode) { typing.parentNode.removeChild(typing); }
            bubble('out', L('genericError'), { kind: 'error' });
        }).finally(function () { submit.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (event) { event.preventDefault(); send(input.value); });
}());
