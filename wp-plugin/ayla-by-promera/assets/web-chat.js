(function () {
    if (localStorage.getItem('aylaChatFlowVersion') !== '3') {
        localStorage.removeItem('aylaVisitorId');
        localStorage.removeItem('aylaConversationId');
        localStorage.removeItem('aylaVisitorProfileComplete');
        localStorage.removeItem('aylaVisitorDetailsSaved');
        localStorage.setItem('aylaChatFlowVersion', '3');
    }

    var copy = AylaWebChat.i18n || {};
    var state = {
        visitorId: localStorage.getItem('aylaVisitorId'),
        conversationId: localStorage.getItem('aylaConversationId'),
        lang: copy[AylaWebChat.lang] ? AylaWebChat.lang : 'en',
        large: false,
        chatHere: false
    };
    if (!state.visitorId) {
        state.visitorId = window.crypto && crypto.randomUUID ? crypto.randomUUID() : '00000000-0000-4000-8000-000000000000';
        localStorage.setItem('aylaVisitorId', state.visitorId);
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
    root.innerHTML = '<button class="ayla-chat__toggle" type="button" aria-expanded="false"><img src="' + AylaWebChat.logoUrl + '" alt=""></button>' +
        '<div class="ayla-chat__panel" hidden><div class="ayla-chat__head"><h2 class="ayla-chat__title"></h2><button class="ayla-chat__icon" type="button" data-size-toggle>&#x26F6;</button></div>' +
        '<div class="ayla-chat__messages" aria-live="polite"></div>' +
        '<form class="ayla-chat__form"><label class="ayla-chat__label" for="ayla-chat-input"></label><input id="ayla-chat-input" name="message" maxlength="2000" required dir="auto" autocomplete="off"><button type="submit"></button></form>' +
        '<small class="ayla-chat__brand"><a href="https://promera.ae" target="_blank" rel="noopener noreferrer">Ayla by Promera</a></small></div>';
    document.body.appendChild(root);

    var toggle = root.querySelector('.ayla-chat__toggle'), panel = root.querySelector('.ayla-chat__panel'), messages = root.querySelector('.ayla-chat__messages'), form = root.querySelector('form');
    var input = form.elements.message, submit = form.querySelector('button'), sizeButton = root.querySelector('[data-size-toggle]');
    var lastWhatsApp = null;
    var whatsappUrl = AylaWebChat.whatsappUrl || null;
    var chooser = null;

    function applyLanguage() {
        root.querySelector('.ayla-chat__title').textContent = L('title');
        root.querySelector('.ayla-chat__label').textContent = L('messageLabel');
        input.placeholder = L('messagePlaceholder');
        submit.textContent = L('send');
        toggle.setAttribute('aria-label', L('title'));
        sizeButton.setAttribute('aria-label', state.large ? L('shrink') : L('expand'));
        panel.setAttribute('dir', state.lang === 'ar' ? 'rtl' : 'ltr');
    }

    function setLanguage(lang) {
        if (lang && lang !== state.lang && copy[lang]) { state.lang = lang; applyLanguage(); }
    }
    applyLanguage();

    toggle.addEventListener('click', function () { panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden)); if (!panel.hidden) { showChooser(); input.focus(); } });
    sizeButton.addEventListener('click', function () {
        state.large = root.classList.toggle('ayla-chat--large');
        sizeButton.innerHTML = state.large ? '&#x26F7;' : '&#x26F6;';
        applyLanguage();
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
        if (lastWhatsApp) { var link = document.createElement('a'); link.href = lastWhatsApp; link.target = '_blank'; link.rel = 'noopener'; link.textContent = L('whatsapp'); messages.appendChild(link); }
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
        return fetch(AylaWebChat.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) { return response.json(); });
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
            state.conversationId = response.data.conversation_id; localStorage.setItem('aylaConversationId', state.conversationId);
            render(response.data.messages, response.data.whatsapp_url);
        }).catch(function () {
            if (typing.parentNode) { typing.parentNode.removeChild(typing); }
            bubble('out', L('genericError'), { kind: 'error' });
        }).finally(function () { submit.disabled = false; input.focus(); });
    }

    form.addEventListener('submit', function (event) { event.preventDefault(); send(input.value); });
}());
