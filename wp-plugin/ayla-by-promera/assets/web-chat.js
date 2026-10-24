(function () {
    var state = { visitorId: localStorage.getItem('aylaVisitorId'), conversationId: localStorage.getItem('aylaConversationId') };
    if (!state.visitorId) {
        state.visitorId = window.crypto && crypto.randomUUID ? crypto.randomUUID() : '00000000-0000-4000-8000-000000000000';
        localStorage.setItem('aylaVisitorId', state.visitorId);
    }

    var root = document.createElement('section');
    root.className = 'ayla-chat';
    root.innerHTML = '<button class="ayla-chat__toggle" type="button" aria-expanded="false">' + AylaWebChat.title + '</button><div class="ayla-chat__panel" hidden><div class="ayla-chat__head">' + AylaWebChat.title + '</div><div class="ayla-chat__messages" aria-live="polite"></div><form class="ayla-chat__form"><input name="message" maxlength="2000" required placeholder="اكتب رسالتك..."><input name="name" maxlength="160" placeholder="الاسم (عند الحجز)"><input name="phone" maxlength="32" placeholder="رقم الهاتف (عند الحجز)"><button type="submit">إرسال</button></form></div>';
    document.body.appendChild(root);
    var toggle = root.querySelector('.ayla-chat__toggle'), panel = root.querySelector('.ayla-chat__panel'), messages = root.querySelector('.ayla-chat__messages'), form = root.querySelector('form');
    toggle.addEventListener('click', function () { panel.hidden = !panel.hidden; toggle.setAttribute('aria-expanded', String(!panel.hidden)); });
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
        }).catch(function () { alert('تعذر إرسال الرسالة الآن.'); }).finally(function () { form.querySelector('button').disabled = false; });
    });
}());