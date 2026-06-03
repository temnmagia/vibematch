// public/assets/app.js

document.addEventListener('DOMContentLoaded', () => {
    // --- NATIVE APP BEHAVIOR FIXES ---
    window.addEventListener('contextmenu', function(e) {
        // Check if the browser supports touch (Mobile/Tablet)
        const isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        
        // Only block the context menu if the user is on a touch device
        if (isTouchDevice) {
            e.preventDefault();
        }
    });
});


// --- Форматування часу (щойно, 5 хв тому) ---
function formatTimeAgo() {
    const timeElements = document.querySelectorAll('.feed-time');
    timeElements.forEach(el => {
        const utcStr = el.getAttribute('data-utc');
        if (!utcStr) return;
        const date = new Date(utcStr.replace(' ', 'T') + 'Z');
        const now = new Date();
        const secondsAgo = Math.floor((now - date) / 1000);
        
        let timeStr = '';
        if (secondsAgo < 60) timeStr = 'щойно';
        else if (secondsAgo < 3600) timeStr = Math.floor(secondsAgo / 60) + ' хв тому';
        else if (secondsAgo < 86400) timeStr = Math.floor(secondsAgo / 3600) + ' год тому';
        else timeStr = Math.floor(secondsAgo / 86400) + ' дн тому';
        
        el.innerText = timeStr;
    });
}

// Запускаємо таймер, коли сторінка завантажилась
document.addEventListener('DOMContentLoaded', () => {
    formatTimeAgo();
    setInterval(formatTimeAgo, 60000);
});

// --- Реакції на фільми ---
function sendReaction(emoji, movieId, button, friendId = null, contextJson = null) {
    fetch('../api/react.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ movie_id: movieId, reaction: emoji })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            button.style.background = 'rgba(138, 43, 226, 0.4)';
            button.style.borderColor = '#d8b4fe';
            button.style.transform = 'scale(1.1)';
            setTimeout(() => button.style.transform = 'scale(1)', 200);
            
            // Якщо є чат і це картка друга — відправляємо повідомлення
            if (friendId && contextJson && typeof sendChatMessage !== 'undefined') {
                let ctx = JSON.parse(contextJson);
                ctx.emoji = emoji;
                currentChatFriendId = friendId;
                sendChatMessage(' ', JSON.stringify(ctx)); 
            }
        }
    })
    .catch(err => console.error('Помилка реакції:', err));
}

function rateGenreMatch(movieId, button) {
    fetch('../api/react.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ movie_id: movieId, reaction: 'accuracy' })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            button.innerHTML = "Дякуємо! ❤️";
            button.style.background = "rgba(138, 43, 226, 0.4)";
            button.style.color = "white";
            button.style.borderColor = "transparent";
            button.disabled = true;
        }
    });
}

/* ========================================================
   ФОНОВА СИНХРОНІЗАЦІЯ ДРУЗІВ ТА ЗАПИТІВ
======================================================== */

// Допоміжна функція для безпеки
function escapeHtml(unsafe) {
    return (unsafe || '').toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

let isSyncing = false; // Захист від подвійних запитів

// Синхронізація стрічки активності
function syncFeed(serverFeed) {
    const feedContainer = document.querySelector('.friends-feed .section-card');
    if (!feedContainer) return;

    // Видаляємо елементи тих, кого вже немає в друзях
    const serverIds = new Set(serverFeed.map(i => String(i.id)));
    feedContainer.querySelectorAll('.feed-item[data-activity-id]').forEach(el => {
        if (!serverIds.has(el.getAttribute('data-activity-id'))) {
            el.style.transition = 'all 0.3s ease';
            el.style.opacity = '0';
            el.style.transform = 'scale(0.95)';
            setTimeout(() => el.remove(), 300);
        }
    });

    // Додаємо нові елементи яких ще немає на сторінці
    const existingIds = new Set(
        Array.from(feedContainer.querySelectorAll('.feed-item[data-activity-id]'))
            .map(el => el.getAttribute('data-activity-id'))
    );

    const iconMap = {
        favorite: '💜', vote_up: '⬆️', vote_down: '⬇️',
        react_fire: '🔥', react_cry: '😭', react_alien: '👽', accuracy_match: '🎯'
    };
    const actionMap = {
        favorite: 'додав у закладки', vote_up: 'оцінив (+1)', vote_down: 'знизив рейтинг',
        react_fire: 'вважає вогненним', react_cry: 'плаче від',
        react_alien: 'зловив дивний вайб від', accuracy_match: 'підтвердив точність вайбу для'
    };

    // Знаходимо перший реальний feed-item або кінець контейнера для вставки на початок
    const firstItem = feedContainer.querySelector('.feed-item');
    const emptyState = feedContainer.querySelector('.empty-state');

    serverFeed.forEach(item => {
        if (existingIds.has(String(item.id))) return;

        if (emptyState) emptyState.remove();

        const icon   = iconMap[item.type]   || '🎬';
        const action = actionMap[item.type] || 'взаємодіяв з';

        const posterHtml = item.image_url
            ? `<img src="${escapeHtml(item.image_url)}" alt="poster" class="feed-poster">`
            : `<div class="feed-poster-placeholder"></div>`;

        const el = document.createElement('div');
        el.className = 'feed-item';
        el.setAttribute('data-user-id', item.user_id);
        el.setAttribute('data-activity-id', item.id);
        el.style.opacity = '0';
        el.style.transform = 'scale(0.95)';
        el.style.transition = 'all 0.3s ease';
        el.innerHTML = `
            ${posterHtml}
            <div class="feed-text">
                <span style="font-size:1.2rem;margin-right:5px;">${icon}</span>
                <strong>${escapeHtml(item.user_name)}</strong> ${action}
                <a href="movie.php?id=${item.tmdb_id}"><em>${escapeHtml(item.title)}</em></a>
                <span class="feed-time" data-utc="${escapeHtml(item.created_at)}"></span>
            </div>
        `;

        if (firstItem) {
            feedContainer.insertBefore(el, firstItem);
        } else {
            feedContainer.appendChild(el);
        }

        setTimeout(() => {
            el.style.opacity = '1';
            el.style.transform = 'scale(1)';
        }, 50);
    });

    // Оновлюємо відносний час для нових елементів
    formatTimeAgo();
}

function startFriendSync() {
    // Перше завантаження одразу
    fetchFriendsData();
    // Далі кожні 5 секунд у фоні
    setInterval(fetchFriendsData, 5000);
}

// Головна функція, яка тягне актуальні дані з бази
function fetchFriendsData() {
    if (isSyncing) return;
    isSyncing = true;

    fetch('../api/sync_friends.php')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            updateCounters(data);
            syncSection('incoming', data.incoming);
            syncSection('sent', data.sent);
            syncSection('friends', data.friends);
            syncFeed(data.feed || []);
        }
        isSyncing = false;
    })
    .catch(err => {
        console.error('Помилка синхронізації:', err);
        isSyncing = false;
    });
}

// Оновлюємо тільки цифри
function updateCounters(data) {
    const fc = document.getElementById('friendsCount');
    if (fc) fc.innerText = data.friends.length;
    
    const ic = document.getElementById('incomingCount');
    if (ic) ic.innerText = data.incoming.length;
    
    const sc = document.getElementById('sentCount');
    if (sc) sc.innerText = data.sent.length;
}

// Універсальний будівничий списків
function syncSection(type, serverData) {
    const list = document.getElementById(type + 'List');
    const section = document.getElementById(type + 'Section');
    if (!list || !section) return;

    // 1. ВИДАЛЯЄМО тих, кого більше немає на сервері (плавно)
    const existingRows = Array.from(list.querySelectorAll('.user-row'));
    existingRows.forEach(row => {
        const id = parseInt(row.getAttribute('data-user-id'));
        if (!serverData.find(u => parseInt(u.id) === id)) {
            if (row.style.opacity !== '0') {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'scale(0.95)';
                setTimeout(() => row.remove(), 300);
            }
        }
    });

    // 2. ДОДАЄМО нових
    serverData.forEach(user => {
        const id = parseInt(user.id);
        if (!list.querySelector(`.user-row[data-user-id="${id}"]`)) {
            // Знищуємо текст про відсутність друзів перед додаванням
            const emptyState = list.querySelector('.empty-state');
            if (emptyState) emptyState.remove();

            const newRow = createRowElement(type, user);
            list.appendChild(newRow);
            
            // Запускаємо анімацію появи
            setTimeout(() => {
                newRow.style.opacity = '1';
                newRow.style.transform = 'scale(1)';
            }, 50);
        }
    });

    // 3. Перевіряємо порожнечу (через 350мс, коли закінчаться анімації видалення)
    setTimeout(() => {
        const rowCount = list.querySelectorAll('.user-row').length;
        if (rowCount > 0) {
            section.style.display = 'block';
        } else {
            if (type === 'friends') {
                section.style.display = 'block'; // Секція друзів завжди видима
                if (!list.querySelector('.empty-state')) {
                    list.innerHTML = '<p class="empty-state">У тебе ще немає друзів. Знайди їх вище!</p>';
                }
            } else {
                section.style.display = 'none'; // Порожні вхідні/надіслані ховаються
            }
        }
    }, 350);
}

// Генератор HTML для рядка користувача
function createRowElement(type, user) {
    const row = document.createElement('div');
    row.className = 'user-row';
    row.setAttribute('data-user-id', user.id);
    row.style.opacity = '0';
    row.style.transform = 'scale(0.95)';
    row.style.transition = 'all 0.3s ease';

    const avatarHtml = user.avatar 
        ? `<img src="${escapeHtml(user.avatar)}" class="user-avatar">`
        : `<div class="user-avatar">${escapeHtml(user.name).charAt(0).toUpperCase()}</div>`;
    
    const escapedName = escapeHtml(user.name).replace(/'/g, "\\'");
    
    let actionsHtml = '';
    if (type === 'incoming') {
        actionsHtml = `
            <button class="btn-sm btn-accept" onclick="handleFriendAction('accept', ${user.id}, this)">✅</button>
            <button class="btn-sm btn-reject" onclick="handleFriendAction('reject', ${user.id}, this)">❌</button>
        `;
    } else if (type === 'sent') {
        actionsHtml = `
            <button class="btn-sm btn-remove" onclick="handleFriendAction('remove', ${user.id}, this)">Скасувати</button>
        `;
    } else if (type === 'friends') {
        actionsHtml = `
            <button class="btn-sm btn-chat" onclick="openChat(${user.id}, '${escapedName}')">💬</button>
            <a class="btn-sm btn-chat" href="profile.php?id=${user.id}" style="text-decoration:none;">👤</a>
            <button class="btn-sm btn-remove" onclick="handleFriendAction('remove', ${user.id}, this)">🗑️</button>
        `;
    }

    row.innerHTML = `
        ${avatarHtml}
        <div class="user-info">
            <strong>${escapeHtml(user.name)}</strong>
            <small>${escapeHtml(user.email)}</small>
        </div>
        <div class="user-actions">${actionsHtml}</div>
    `;
    return row;
}

/* ========================================================
   ОБРОБКА КНОПОК (Додати, Прийняти, Видалити)
======================================================== */
function handleFriendAction(action, targetId, btn) {
    const originalText = btn.innerText;
    
    // Запам'ятовуємо, чи ми в блоці пошуку
    const sectionHeader = btn.closest('.section-card')?.querySelector('h3')?.innerText || '';
    const isSearchSection = sectionHeader.includes('Знайти');

    btn.innerText = '...';
    btn.disabled = true;

    fetch('../actions/friend_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            'action': action,
            'target_id': targetId,
            'is_ajax': 1
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Оновлюємо дані, тільки якщо функція існує на поточній сторінці
            if (typeof fetchFriendsData === 'function') {
                fetchFriendsData();
            }

            if (action === 'remove' && !isSearchSection) {
                document.querySelectorAll(`.feed-item[data-user-id="${targetId}"]`).forEach(item => {
                    item.style.transition = 'all 0.3s ease';
                    item.style.opacity = '0';
                    item.style.transform = 'scale(0.95)';
                    setTimeout(() => item.remove(), 300);
                });
            }
            
            // Змінюємо вигляд кнопки ТІЛЬКИ якщо ми в секції Пошуку 
            if (isSearchSection) {
                if (action === 'send') {
                    // Очищаємо поле пошуку і всі результати
                    const searchInput = document.querySelector('input[name="search"]');
                    if (searchInput) searchInput.value = '';

                    const searchSection = btn.closest('.section-card');
                    searchSection.querySelectorAll('.user-row').forEach(row => {
                        row.style.transition = 'all 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'scale(0.95)';
                        setTimeout(() => row.remove(), 300);
                    });
                } else if (action === 'remove') {
                    btn.className = 'btn-sm btn-add';
                    btn.innerText = '+ Додати';
                    btn.onclick = () => handleFriendAction('send', targetId, btn);
                    btn.disabled = false;
                } else if (action === 'accept') {
                    const rejectBtn = btn.closest('.user-actions')?.querySelector('.btn-reject');
                    if (rejectBtn) rejectBtn.remove();
                    btn.outerHTML = `<span class="btn-sm btn-pending">✅ Друзі</span>`;
                }
            } else {
                // ====================================================
                // ЛОГІКА ДЛЯ ГОЛОВНОЇ СТОРІНКИ ("Можливо знайомі")
                // ====================================================
                if (action === 'send') {
                    btn.innerText = '✓ Надіслано';
                    btn.style.background = 'rgba(255, 255, 255, 0.1)';
                    btn.style.color = 'var(--text-muted, #aaa)';
                    btn.style.boxShadow = 'none';
                    btn.style.cursor = 'default';
                }
            }
        } else {
            console.error("Помилка сервера:", data.error);
            btn.innerText = originalText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        console.error('Помилка:', err);
        btn.innerText = originalText;
        btn.disabled = false;
    });
}
/* ========================================================
   AIRBUDS FLYING EMOJIS (INTERSECTION OBSERVER)
======================================================== */

document.addEventListener('DOMContentLoaded', () => {
    // Словник емодзі для кожної дії
    const emojiMap = {
        'favorite':       ['💜', '✨', '🫶'],
        'vote_up':        ['👍', '⬆️', '💜', '🔥'],
        'vote_down':      ['👎', '⬇️', '💔'],
        'react_fire':     ['🔥', '❤️‍🔥', '💥', '🌶️'],
        'react_cry':      ['😭', '💧', '🥺', '🌧️'],
        'react_alien':    ['👽', '🛸', '🌀', '👾'],
        'accuracy_match': ['🎯', '💯', '✨', '🔮'],
        'recommendation': ['🎧', '🎶', '✨', '🎵']
    };

    function spawnEmojiRain(section) {
        const actionType = section.getAttribute('data-action-type');
        if (!actionType || section.dataset.emojisFired === 'true') return;
        
        // Помічаємо, що для цієї картки салют вже був
        section.dataset.emojisFired = 'true'; 

        const emojis = emojiMap[actionType] || ['✨'];
        const amount = Math.floor(Math.random() * 6) + 8; // Від 8 до 14 летючих емодзі

        // 1. ЗАПУСКАЄМО ДОЩ
        for (let i = 0; i < amount; i++) {
            const particle = document.createElement('div');
            particle.className = 'ab-emoji-particle';
            particle.innerText = emojis[Math.floor(Math.random() * emojis.length)];

            particle.style.left = (Math.random() * 80 + 10) + '%'; 
            particle.style.fontSize = (Math.random() * 1.5 + 1.5) + 'rem';
            
            particle.style.setProperty('--duration-y', (Math.random() * 2 + 2.5) + 's');
            particle.style.setProperty('--duration-x', (Math.random() * 1.5 + 1.5) + 's');
            
            particle.style.animationDelay = (Math.random() * 0.4) + 's, ' + (Math.random() * 0.4) + 's';

            section.appendChild(particle);
            setTimeout(() => particle.remove(), 5000);
        }

        // 2. ЗАКРІПЛЮЄМО ЕМОДЗІ ПО ГОРИЗОНТАЛІ (ДЛЯ ВЕЛИКОЇ КІЛЬКОСТІ)
        const badgeWrapper = section.querySelector('.ab-user-badge-wrapper');
        if (badgeWrapper) {
            setTimeout(() => {
                // ⚙️ НАЛАШТУВАННЯ КІЛЬКОСТІ:
                // Math.floor(Math.random() * 3) + 4 дасть від 4 до 6 емодзі
                const pinCount = Math.floor(Math.random() * 3) + 4; 
                
                for (let j = 0; j < pinCount; j++) {
                    const pinned = document.createElement('div');
                    pinned.className = 'ab-pinned-emoji';
                    pinned.innerText = emojis[Math.floor(Math.random() * emojis.length)];
                    
                    // 1. Визначаємо сторону: парні (0, 2, 4) — ліворуч (-1), непарні (1, 3, 5) — праворуч (1)
                    const side = (j % 2 === 0) ? -1 : 1;
                    
                    // 2. Визначаємо, яка це "пара" по рахунку (0-ва пара, 1-ша пара, 2-га пара)
                    const pairIndex = Math.floor(j / 2); 
                    
                    // 3. Рахуємо базову відстань:
                    // 75 - це відступ для найпершої пари.
                    // 50 - це крок, на який кожна наступна пара відлітає далі.
                    const baseDistance = 75 + (pairIndex * 50); 
                    
                    // 4. Фінальна координата X (базова відстань + до 20 пікселів рандому)
                    const x = side * (baseDistance + Math.random() * 20); 
                    
                    // По вертикалі даємо трохи більше свободи (від -15px до +15px)
                    const y = (Math.random() - 0.5) * 30; 
                    
                    const rotation = (Math.random() - 0.5) * 50; // Нахил
                    
                    pinned.style.transform = `translate(${x}px, ${y}px) rotate(${rotation}deg) scale(0)`;
                    badgeWrapper.appendChild(pinned);
                    
                    requestAnimationFrame(() => {
                        setTimeout(() => {
                            pinned.style.transform = `translate(${x}px, ${y}px) rotate(${rotation}deg) scale(1)`;
                        }, 50);
                    });
                }
            }, 2200);
        }
        document.querySelectorAll('.ab-title').forEach(title => {
            // 1. Шрифт
            // Знайди цей рядок в app.js і додай 'font-round'
            const fonts = [
                            'font-default', 'font-glitch', 'font-elegant', 'font-tech', 'font-bold', 'font-space', 'font-round', 
                            'font-cyber', 'font-gothic', 'font-drain', 'font-tall', 'font-chaos'
                        ];
            title.classList.add(fonts[Math.floor(Math.random() * fonts.length)]);

            // 2. Адаптивне масштабування шрифту
            const textLength = title.textContent.length;
            let fontSize = 1.8; // Базовий великий розмір (rem)

            if (textLength > 36) {
                fontSize = 0.9; // Якщо назва дуже довга (як "Неймовірні пригоди..."), ставимо 1rem
            } else if (textLength > 20) {
                fontSize = 1.4; // Середня довжина
            }

            // 3. Рандомний нахил (трохи менший, щоб довгий текст не з'їжджав)
            const randomRotation = (Math.random() * 8 - 4); 

            // Застосовуємо стилі
            title.style.fontSize = `${fontSize}rem`;
            title.style.transform = `rotate(${randomRotation}deg)`;
            title.style.transition = 'all 0.3s ease';
        });
    }

    // Intersection Observer
    const observerOptions = {
        root: null,
        threshold: 0.6 
    };

    const feedObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            // Унікальний ID для кожної картки (можна додати в HTML як data-id="123")
            // Якщо у тебе немає data-id, ми створимо його через внутрішній лічильник
            if (!entry.target.dataset.uniqueId) {
                entry.target.dataset.uniqueId = 'vibe_' + Math.random().toString(36).substr(2, 9);
            }
            
            const cardId = entry.target.dataset.uniqueId;
            const hasFired = sessionStorage.getItem(cardId);

            if (entry.isIntersecting && !hasFired) {
                // Запускаємо салют
                spawnEmojiRain(entry.target);
                
                // Запам'ятовуємо, що ми це вже бачили (до оновлення сторінки)
                sessionStorage.setItem(cardId, 'true');
            }
        });
    }, observerOptions);

    // Вішаємо спостерігач
    document.querySelectorAll('.ab-snap-section').forEach(section => {
        feedObserver.observe(section);
    });
});

// Запускаємо магію тільки на сторінці друзів
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('friendsList')) {
        startFriendSync();
    }
});

/* ========================================================
   СМАРТ-ХЕДЕР (Фінальна Розумна Версія)
======================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.main-header');
    
    if (!header) return;

    let lastScrollTop = 0;
    const scrollThreshold = 80; 

    window.addEventListener('scroll', (e) => {
        // 1. Визначаємо, чи це головний скрол сторінки
        const isWindowScroll = (e.target === document || e.target === window);
        
        // 2. Або чи це скрол твоєї повноекранної стрічки (якщо вона є)
        const isFeedScroll = e.target.classList && e.target.classList.contains('ab-feed-wrapper');
        
        // Якщо скролиться якась дрібниця (наприклад, список пісень у профілі) — просто ІГНОРУЄМО!
        if (!isWindowScroll && !isFeedScroll) {
            return; 
        }

        // Беремо правильну цифру залежно від того, що скролиться
        let currentScroll = isWindowScroll 
            ? (window.scrollY || document.documentElement.scrollTop) 
            : e.target.scrollTop;
        
        if (currentScroll > lastScrollTop && currentScroll > scrollThreshold) {
            // Скролимо вниз — ховаємо
            header.classList.add('header-hidden');
        } else if (currentScroll < lastScrollTop) {
            // Скролимо вгору — показуємо
            header.classList.remove('header-hidden');
        }

        lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;
    }, { passive: true, capture: true }); 
});

/* ========================================================
   ДОДАВАННЯ В ЗАКЛАДКИ (Анімація Сердечка на головній)
======================================================== */
function toggleFavorite(tmdbId, btn) {
    const originalText = btn.innerHTML;
    
    // Візуальна індикація завантаження
    btn.innerHTML = '⏳'; 
    btn.disabled = true;

    // Готуємо дані для відправки
    const formData = new FormData();
    formData.append('tmdb_id', tmdbId);
    formData.append('is_ajax', 1);

    // Відправляємо на твій існуючий файл!
    fetch('../actions/toggle_favorite.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            if (data.is_favorite) {
                // Додано в закладки
                btn.innerHTML = '💖'; 
                btn.style.background = 'rgba(255, 20, 147, 0.2)'; // Легкий рожевий фон
                btn.style.transform = 'scale(1.2)'; // Ефект пульсації
                setTimeout(() => btn.style.transform = 'scale(1)', 200);
            } else {
                // Видалено з закладок
                btn.innerHTML = '🤍'; // Або 💜, якщо хочеш
                btn.style.background = ''; 
            }
        } else {
            console.error("Помилка:", data.error);
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error('Помилка fetch:', err);
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}