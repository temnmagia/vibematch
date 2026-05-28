<div id="chatDrawer" class="chat-drawer">
    <div class="chat-drawer-header">
        <div class="chat-friend-info">
            <div class="chat-avatar-placeholder">💬</div>
            <h4 id="chatFriendName" class="chat-friend-name">Друг</h4>
        </div>
        <button class="chat-close-btn" onclick="closeChat()">✕</button>
    </div>
    
    <div id="chatMessages" class="chat-messages">
        <p class="chat-loading">Завантаження переписки...</p>
    </div>
    
    <div class="chat-input-area">
        <div id="chatContextPreview" class="chat-context-preview" style="display:none;"></div>
        
        <div style="display:flex; gap:12px; width: 100%;">
            <input 
    type="text" 
    id="chatInput" 
    inputmode="text"
    placeholder="Напишіть щось..." 
    autocomplete="off"
    onclick="this.focus()"
    onkeypress="if(event.key === 'Enter') sendChatMessage()">
            <button onclick="sendChatMessage()" class="chat-send-btn">🚀</button>
        </div>
    </div>
</div>

<script>
let currentChatFriendId = null;
let chatInterval = null;
let currentReplyContext = null;

function openChat(friendId, friendName, contextJson = null) {
    currentChatFriendId = friendId;
    document.getElementById('chatFriendName').innerText = friendName;
    
    const contextPreview = document.getElementById('chatContextPreview');
    if (contextJson) {
        currentReplyContext = contextJson;
        try {
            const ctx = JSON.parse(contextJson);
            contextPreview.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px;">
                    <img src="${ctx.image}" style="width:30px; height:30px; object-fit:cover; border-radius:4px;">
                    <span>Відповідь на: <strong>${ctx.title}</strong></span>
                </div>
                <button onclick="clearContext()" class="clear-ctx-btn">✕</button>`;
        } catch(e) {
            contextPreview.innerHTML = `<strong>Відповідь:</strong> ${contextJson} <button onclick="clearContext()" class="clear-ctx-btn">✕</button>`;
        }
        contextPreview.style.display = 'flex';
    } else {
        clearContext();
    }
    
    document.getElementById('chatDrawer').classList.add('open');
    document.body.classList.add('chat-open');
    fetchChatHistory();
    clearInterval(chatInterval);
    chatInterval = setInterval(fetchChatHistory, 3000);

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', _onViewportResize);
    }
}

function closeChat() {
    document.getElementById('chatDrawer').classList.remove('open');
    document.getElementById('chatDrawer').style.bottom = '';
    document.body.classList.remove('chat-open');
    currentChatFriendId = null;
    clearInterval(chatInterval);
    clearContext();

    if (window.visualViewport) {
        window.visualViewport.removeEventListener('resize', _onViewportResize);
    }
}

function clearContext() {
    currentReplyContext = null;
    const contextPreview = document.getElementById('chatContextPreview');
    if(contextPreview) contextPreview.style.display = 'none';
}

function fetchChatHistory() {
    if (!currentChatFriendId) return;
        fetch(`../api/chat_api.php?action=get_history&friend_id=${currentChatFriendId}`, {
        credentials: 'include'  // ← add this
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const container = document.getElementById('chatMessages');
                let html = '';
                if (data.messages.length === 0) {
                    html = '<p class="chat-empty">Напишіть перше повідомлення!</p>';
                } else {
                    data.messages.forEach(msg => {
                        const isMe = (parseInt(msg.sender_id) === parseInt(data.me));
                        const bubbleClass = isMe ? 'bubble-me' : 'bubble-friend';
                        
                        let ctxHtml = '';
                        if (msg.reply_context) {
                            try {
                                const ctx = JSON.parse(msg.reply_context);
                                const actionText = isMe ? 'Ви відреагували на фільм' : 'Відреагував(ла) на фільм';
                                ctxHtml = `
                                    <div class="chat-airbuds-card">
                                        ${ctx.emoji ? `<div class="chat-floating-emoji">${ctx.emoji}</div>` : ''}
                                        <div class="chat-airbuds-header">${actionText}</div>
                                        <div class="chat-airbuds-content">
                                            <img src="${ctx.image}" class="chat-airbuds-poster">
                                            <div class="chat-airbuds-info">
                                                <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/8/89/Tmdb-logo.svg/500px-Tmdb-logo.svg.png" style="height:10px; opacity:0.8; margin-bottom:4px;">
                                                <strong>${ctx.title}</strong>
                                            </div>
                                        </div>
                                    </div>`;
                            } catch(e) {
                                ctxHtml = `<div class="msg-context-block">${escapeHtml(msg.reply_context)}</div>`;
                            }
                        }
                        
                        // Якщо повідомлення пусте (просто реакція), не малюємо порожній текст
                        let msgText = msg.message.trim() ? `${escapeHtml(msg.message)}` : '';
                        
                        html += `<div class="message-row ${isMe ? 'row-me' : 'row-friend'}">
                                    <div class="message-bubble ${bubbleClass}">
                                        ${ctxHtml}
                                        ${msgText}
                                    </div>
                                 </div>`;
                    });
                }
                const isAtBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;
                container.innerHTML = html;
                if (isAtBottom || html.includes('chat-empty')) container.scrollTop = container.scrollHeight;
            }
        })
        .catch(err => console.error(err));
}

function sendChatMessage(overrideMessage = null, overrideContext = null) {
    const input = document.getElementById('chatInput');
    const message = overrideMessage !== null ? overrideMessage : input.value.trim();
    const context = overrideContext !== null ? overrideContext : currentReplyContext;
    
    // Дозволяємо відправку, якщо є хоча б текст АБО контекст
    if (!message && !context) return;
    
    if (overrideMessage === null) input.value = '';
    
    fetch('../api/chat_api.php?action=send', {
        method: 'POST',
        credentials: 'include',  // ← add this
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ friend_id: currentChatFriendId, message: message, context: context })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (overrideContext === null) clearContext();
            fetchChatHistory();
        }
    })
    .catch(err => console.error(err));
}

function escapeHtml(text) {
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function _onViewportResize() {
    const drawer = document.getElementById('chatDrawer');
    if (!drawer.classList.contains('open')) return;
    const keyboardHeight = window.innerHeight - window.visualViewport.height;
    if (keyboardHeight > 50) {
        drawer.style.height = window.visualViewport.height + 'px';
        drawer.style.bottom = '0';
    } else {
        drawer.style.height = '';
        drawer.style.bottom = '0';
    }
    const msgs = document.getElementById('chatMessages');
    if (msgs) setTimeout(() => msgs.scrollTop = msgs.scrollHeight, 100);
}
</script>