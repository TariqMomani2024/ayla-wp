(function () {
    var state = { visitorId: localStorage.getItem('aylaVisitorId'), conversationId: localStorage.getItem('aylaConversationId'), visitorDetailsSaved: localStorage.getItem('aylaVisitorDetailsSaved') === '1' };
    if (!state.visitorId) {
        state.visitorId = window.crypto && crypto.randomUUID ? crypto.randomUUID() : '00000000-0000-4000-8000-000000000000';
        localStorage.setItem('aylaVisitorId', state.visitorId);
    }

    var root = document.createElement('section');
    root.className = 'ayla-chat';
    root.innerHTML = '<button class="ayla-chat__toggle" type="button" aria-expanded="false" aria-label="' + AylaWebChat.title + '"><img src="' + AylaWebChat.logoUrl + '" alt=""></button><div class="ayla-chat__panel" hidden><div class="ayla-chat__head"><h2 class="ayla-chat__title">' + AylaWebChat.title + '</h2><div class="ayla-chat__size"><button class="ayla-chat__icon" type="button" data-size="smaller" aria-label="تصغير المحادثة">−</button><button class="ayla-chat__icon" type="button" data-size="larger" aria-label="تكبير المحادثة">+</button></div></div><div class="ayla-chat__messages" aria-live="polite"></div><form class="ayla-chat__form"><input name="message" maxlength="2000" required placeholder="اكتب رسالتك..."><div class="ayla-chat__visitor-fields"' + (state.visitorDetailsSaved ? ' hidden' : '') + '><input name="name" maxlength="160" placeholder="الاسم"><input name="phone" maxlength="32" placeholder="رقم الهاتف"></div><button type="submit">إرسال</button></form><small class="ayla-chat__brand"><a href="https://promera.ae" target="_blank" rel="noopener noreferrer">Ayla by Promera</a></small></div>';
    document.body.appendChild(root);
    var toggle = root.querySelector('.ayla-chat__toggle'), panel = root.querySelector('.ayla-chat__panel'), messages = root.querySelector('.ayla-chat__messages'), form = root.querySelector('form');
    toggle.addEventListener('click', function () { panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden)); });
    root.querySelector('[data-size="larger"]').addEventListener('click', function () { root.classList.add('ayla-chat--large'); });
    root.querySelector('[data-size="smaller"]').addEventListener('click', function () { root.classList.remove('ayla-chat--large'); });
    function render(items, whatsappUrl) {
        messages.innerHTML = '';
        items.forEach(function (item) { var el = document.createElement('p'); el.className = 'ayla-chat__message ayla-chat__message--' + item.direction; el.textContent = item.body; messages.appendChild(el); });
        if (whatsappUrl) { var link = document.createElement('a'); link.href = whatsappUrl; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'التواصل عبر WhatsApp'; messages.appendChild(link); }
        messages.scrollTop = messages.scrollHeight;
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var data = new FormData(form); data.append('action', 'ayla_web_chat'); data.append('nonce', AylaWebChat.nonce); data.append('visitor_id', state.visitorId); if (state.conversationId) { data.append('conversation_id', state.conversationId); }
        form.querySelector('button').disabled = true;
        fetch(AylaWebChat.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) { return response.json(); }).then(function (response) {
            if (!response.success) { throw new Error(response.data && response.data.message); }
            state.conversationId = response.data.conversation_id; localStorage.setItem('aylaConversationId', state.conversationId); render(response.data.messages, response.data.whatsapp_url); form.elements.message.value = '';
            if (form.elements.name.value.trim() && form.elements.phone.value.trim()) { state.visitorDetailsSaved = true; localStorage.setItem('aylaVisitorDetailsSaved', '1'); root.querySelector('.ayla-chat__visitor-fields').hidden = true; }
        }).catch(function () { alert('تعذر إرسال الرسالة الآن.'); }).finally(function () { form.querySelector('button').disabled = false; });
    });
}());