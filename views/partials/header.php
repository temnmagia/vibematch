<?php
// views/partials/header.php

// Ініціалізуємо змінні для лічильників сповіщень
$headerPendingFriends = 0;
$headerUnreadMessages = 0;

// Якщо користувач залогінений, хедер сам дістає свіжі дані з БД для бейджів
if (!empty($IS_LOGGED_IN) && isset($pdo)) {
    // Кількість запитів дружби
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE receiver_id = ? AND status = 'pending'");
    $stmt->execute([$CURRENT_USER_ID]);
    $headerPendingFriends = (int)$stmt->fetchColumn();

    // Кількість непрочитаних повідомлень
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $stmt->execute([$CURRENT_USER_ID]);
    $headerUnreadMessages = (int)$stmt->fetchColumn();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Дозволяємо динамічно міняти класи хедера (наприклад, для ефекту скла на головній)
$headerClassString = $headerClass ?? 'main-header';
?>

<header class="<?= htmlspecialchars($headerClassString) ?>">
    <h1 class="app-title">
        <a href="index.php" class="brand-logo-wrapper">
            <span class="brand-icon-shimmer"></span>
            <span class="brand-text">VibeMatch</span>
        </a>
    </h1>

    <nav class="header-nav">
        <?php if (!$IS_LOGGED_IN): ?>
            <a href="../actions/spotify_auth.php" class="btn auth-btn glass-btn">Авторизуватись</a>
        <?php else: ?>
            <a href="index.php" class="btn glass-btn">🏠 Головна</a>
            
            <a href="friends.php" class="btn style-friends-btn glass-btn">
                👥 Друзі
                <?php if ($headerPendingFriends > 0): ?>
                    <span class="badge"><?= $headerPendingFriends ?></span>
                <?php endif; ?>
                <?php if ($headerUnreadMessages > 0): ?>
                    <span class="badge" style="background: #ef4444; margin-left: 5px;">💬 <?= $headerUnreadMessages ?></span>
                <?php endif; ?>
            </a>

            <div class="dropdown">
                <a href="profile.php" class="btn cabinet-btn glass-btn">Кабінет</a>
                <div class="dropdown-content glass-dropdown">
                    <form method="post" class="dropdown-form-button" style="display:inline;">
                        <button type="submit" name="logout">Вийти</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </nav>
</header>

    <?php if (!empty($IS_LOGGED_IN)): ?>
    <nav class="mobile-tab-bar">
        <a onclick="window.location.href='index.php'" class="tab-btn <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.47 3.841a.75.75 0 0 1 1.06 0l8.69 8.69a.75.75 0 1 0 1.06-1.061l-8.689-8.69a2.25 2.25 0 0 0-3.182 0l-8.69 8.69a.75.75 0 1 0 1.061 1.06l8.69-8.689Z" /><path d="m12 5.432 8.159 8.159c.03.03.06.058.091.086v6.198c0 1.035-.84 1.875-1.875 1.875H15a.75.75 0 0 1-.75-.75v-4.5a.75.75 0 0 0-.75-.75h-3a.75.75 0 0 0-.75.75V21a.75.75 0 0 1-.75.75H5.625a1.875 1.875 0 0 1-1.875-1.875v-6.198a2.29 2.29 0 0 0 .091-.086L12 5.432Z" /></svg>
        </a>
        
        <a onclick="window.location.href='friends.php'" class="tab-btn <?= ($currentPage == 'friends.php') ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4.5 6.375a4.125 4.125 0 1 1 8.25 0 4.125 4.125 0 0 1-8.25 0ZM14.25 8.625a3.375 3.375 0 1 1 6.75 0 3.375 3.375 0 0 1-6.75 0ZM1.5 19.125a7.125 7.125 0 0 1 14.25 0v.003l-.001.119a.75.75 0 0 1-.363.63 13.067 13.067 0 0 1-6.761 1.873c-2.472 0-4.786-.684-6.76-1.873a.75.75 0 0 1-.364-.63l-.001-.122ZM17.25 19.128l-.001.144a2.25 2.25 0 0 1-.233.96 10.088 10.088 0 0 0 5.06-1.01.75.75 0 0 0 .42-.643 4.875 4.875 0 0 0-6.957-4.611 8.586 8.586 0 0 1 1.71 5.157v.003Z" /></svg>
            <?php if ($headerPendingFriends > 0): ?>
                <span class="tab-badge" style="background: #ef4444;"></span>
            <?php endif; ?>
        </a>

        <a onclick="window.location.href='profile.php'" class="tab-btn <?= ($currentPage == 'profile.php') ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z" clip-rule="evenodd" /></svg>
        </a>
    </nav>
    <?php endif; ?>