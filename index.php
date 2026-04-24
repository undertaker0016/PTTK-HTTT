<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('Content-Type: text/html; charset=UTF-8');

$appName = 'LifeBoard';
$dbPath = __DIR__ . '/storage/personal_manager.sqlite';
$setupError = null;

$expenseCategories = [
    'Ăn uống',
    'Di chuyển',
    'Học tập',
    'Mua sắm',
    'Giải trí',
    'Sức khỏe',
    'Khác',
];

$todoPriorities = ['Cao', 'Trung bình', 'Thấp'];
$todoStatuses = ['pending' => 'Đang làm', 'done' => 'Hoàn thành'];
$pageTitles = [
    'dashboard' => 'Tổng quan',
    'expenses' => 'Quản lý chi tiêu',
    'todos' => 'Quản lý công việc',
    'notes' => 'Ghi chú',
    'qr' => 'Tạo mã QR',
    'reports' => 'Báo cáo thống kê',
];
$pageDescriptions = [
    'dashboard' => 'Theo dõi nhanh toàn bộ hoạt động cá nhân trên một dashboard gọn gàng, dễ demo.',
    'expenses' => 'Thêm, sửa, xóa các khoản chi và xem lịch sử được phân tách theo từng tháng.',
    'todos' => 'Quản lý công việc cá nhân, cập nhật trạng thái và theo dõi tiến độ thực hiện.',
    'notes' => 'Lưu ghi chú học tập, ý tưởng, việc nhóm và những nội dung quan trọng.',
    'qr' => 'Nhập nội dung bất kỳ để tạo mã QR phục vụ demo, chia sẻ link hoặc thông tin nhanh.',
    'reports' => 'Xem biểu đồ cột, biểu đồ tròn và các chỉ số tổng hợp để thuyết trình.',
];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_with_flash(string $type, string $message, string $page = 'dashboard', array $params = []): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    $query = array_merge(['page' => $page], $params);
    header('Location: index.php?' . http_build_query($query));
    exit;
}

function fetch_all_assoc(SQLite3Result $result): array
{
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function fetch_one_assoc(SQLite3 $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $type = SQLITE3_TEXT;
        if (is_int($value)) {
            $type = SQLITE3_INTEGER;
        } elseif (is_float($value)) {
            $type = SQLITE3_FLOAT;
        }
        $stmt->bindValue($key, $value, $type);
    }

    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);

    return $row !== false ? $row : null;
}

function month_key(string $date): string
{
    return date('Y-m', strtotime($date));
}

function month_label_from_key(string $monthKey): string
{
    return 'Tháng ' . date('m/Y', strtotime($monthKey . '-01'));
}

function format_money(float $amount): string
{
    return number_format($amount, 0, ',', '.') . ' VND';
}

function selected_page(array $pageTitles): string
{
    $page = $_GET['page'] ?? 'dashboard';
    return array_key_exists($page, $pageTitles) ? $page : 'dashboard';
}

if (!class_exists('SQLite3')) {
    $setupError = 'PHP của bạn chưa bật extension SQLite3. Hãy bật SQLite3 trong Laragon để ứng dụng hoạt động.';
}

$db = null;

if ($setupError === null) {
    try {
        $db = new SQLite3($dbPath);
        if (method_exists($db, 'enableExceptions')) {
            $db->enableExceptions(true);
        }
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec(
            'CREATE TABLE IF NOT EXISTS expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                amount REAL NOT NULL,
                category TEXT NOT NULL,
                description TEXT,
                spent_on TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS todos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                due_date TEXT,
                priority TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                created_at TEXT NOT NULL
            )'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                mood TEXT,
                created_at TEXT NOT NULL
            )'
        );
    } catch (Throwable $exception) {
        $setupError = 'Không thể khởi tạo SQLite: ' . $exception->getMessage();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $db instanceof SQLite3 && $setupError === null) {
    $action = $_POST['action'] ?? '';
    $redirectPage = $_POST['redirect_page'] ?? 'dashboard';
    if (!array_key_exists($redirectPage, $pageTitles)) {
        $redirectPage = 'dashboard';
    }

    try {
        if ($action === 'save_expense') {
            $expenseId = (int) ($_POST['expense_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);
            $category = trim((string) ($_POST['category'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $spentOn = trim((string) ($_POST['spent_on'] ?? ''));

            if ($amount <= 0 || $category === '' || $spentOn === '') {
                redirect_with_flash('error', 'Vui lòng nhập đầy đủ thông tin khoản chi.', $redirectPage);
            }

            if ($expenseId > 0) {
                $stmt = $db->prepare(
                    'UPDATE expenses
                     SET amount = :amount, category = :category, description = :description, spent_on = :spent_on
                     WHERE id = :id'
                );
                $stmt->bindValue(':id', $expenseId, SQLITE3_INTEGER);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO expenses (amount, category, description, spent_on, created_at)
                     VALUES (:amount, :category, :description, :spent_on, :created_at)'
                );
                $stmt->bindValue(':created_at', date('c'), SQLITE3_TEXT);
            }

            $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
            $stmt->bindValue(':category', $category, SQLITE3_TEXT);
            $stmt->bindValue(':description', $description, SQLITE3_TEXT);
            $stmt->bindValue(':spent_on', $spentOn, SQLITE3_TEXT);
            $stmt->execute();

            redirect_with_flash('success', $expenseId > 0 ? 'Đã cập nhật khoản chi.' : 'Đã thêm khoản chi mới.', 'expenses');
        }

        if ($action === 'delete_expense') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM expenses WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            redirect_with_flash('success', 'Đã xóa khoản chi.', 'expenses');
        }

        if ($action === 'save_todo') {
            $todoId = (int) ($_POST['todo_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));
            $priority = trim((string) ($_POST['priority'] ?? 'Trung bình'));
            $status = trim((string) ($_POST['status'] ?? 'pending'));

            if ($title === '') {
                redirect_with_flash('error', 'Vui lòng nhập tên công việc.', $redirectPage);
            }

            if (!array_key_exists($status, $todoStatuses)) {
                $status = 'pending';
            }

            if ($todoId > 0) {
                $stmt = $db->prepare(
                    'UPDATE todos
                     SET title = :title, due_date = :due_date, priority = :priority, status = :status
                     WHERE id = :id'
                );
                $stmt->bindValue(':id', $todoId, SQLITE3_INTEGER);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO todos (title, due_date, priority, status, created_at)
                     VALUES (:title, :due_date, :priority, :status, :created_at)'
                );
                $stmt->bindValue(':created_at', date('c'), SQLITE3_TEXT);
            }

            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->bindValue(':due_date', $dueDate, SQLITE3_TEXT);
            $stmt->bindValue(':priority', $priority, SQLITE3_TEXT);
            $stmt->bindValue(':status', $status, SQLITE3_TEXT);
            $stmt->execute();

            redirect_with_flash('success', $todoId > 0 ? 'Đã cập nhật công việc.' : 'Đã thêm công việc mới.', 'todos');
        }

        if ($action === 'toggle_todo') {
            $id = (int) ($_POST['id'] ?? 0);
            $currentStatus = (string) ($_POST['current_status'] ?? 'pending');
            $nextStatus = $currentStatus === 'done' ? 'pending' : 'done';

            $stmt = $db->prepare('UPDATE todos SET status = :status WHERE id = :id');
            $stmt->bindValue(':status', $nextStatus, SQLITE3_TEXT);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            redirect_with_flash('success', 'Đã cập nhật trạng thái công việc.', 'todos');
        }

        if ($action === 'delete_todo') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM todos WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            redirect_with_flash('success', 'Đã xóa công việc.', 'todos');
        }

        if ($action === 'save_note') {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $content = trim((string) ($_POST['content'] ?? ''));
            $mood = trim((string) ($_POST['mood'] ?? ''));

            if ($title === '' || $content === '') {
                redirect_with_flash('error', 'Vui lòng nhập tiêu đề và nội dung ghi chú.', $redirectPage);
            }

            if ($noteId > 0) {
                $stmt = $db->prepare(
                    'UPDATE notes
                     SET title = :title, content = :content, mood = :mood
                     WHERE id = :id'
                );
                $stmt->bindValue(':id', $noteId, SQLITE3_INTEGER);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO notes (title, content, mood, created_at)
                     VALUES (:title, :content, :mood, :created_at)'
                );
                $stmt->bindValue(':created_at', date('c'), SQLITE3_TEXT);
            }

            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->bindValue(':content', $content, SQLITE3_TEXT);
            $stmt->bindValue(':mood', $mood, SQLITE3_TEXT);
            $stmt->execute();

            redirect_with_flash('success', $noteId > 0 ? 'Đã cập nhật ghi chú.' : 'Đã lưu ghi chú mới.', 'notes');
        }

        if ($action === 'delete_note') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM notes WHERE id = :id');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

            redirect_with_flash('success', 'Đã xóa ghi chú.', 'notes');
        }
    } catch (Throwable $exception) {
        redirect_with_flash('error', 'Có lỗi khi xử lý dữ liệu: ' . $exception->getMessage(), $redirectPage);
    }
}

$currentPage = selected_page($pageTitles);
$expenses = [];
$todos = [];
$notes = [];
$recentExpenses = [];
$recentNotes = [];
$pendingTodos = [];
$upcomingTodos = [];
$expenseGroups = [];
$editingExpense = null;
$editingTodo = null;
$editingNote = null;
$stats = [
    'monthlyTotal' => 0.0,
    'totalExpenses' => 0.0,
    'expenseCount' => 0,
    'pendingTodos' => 0,
    'completedTodos' => 0,
    'completionRate' => 0,
    'noteCount' => 0,
    'overdueTodos' => 0,
];
$monthlyChart = [];
$categoryChart = [];
$todoChart = [];
$topCategory = null;
$insightText = 'Chưa có đủ dữ liệu để phân tích.';

if ($db instanceof SQLite3 && $setupError === null) {
    $expenses = fetch_all_assoc($db->query('SELECT * FROM expenses ORDER BY spent_on DESC, id DESC'));
    $todos = fetch_all_assoc(
        $db->query(
            'SELECT * FROM todos
             ORDER BY CASE WHEN status = "pending" THEN 0 ELSE 1 END, due_date IS NULL, due_date ASC, id DESC'
        )
    );
    $notes = fetch_all_assoc($db->query('SELECT * FROM notes ORDER BY id DESC'));

    $currentMonth = date('Y-m');
    $today = date('Y-m-d');
    $monthBuckets = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("-{$i} month"));
        $monthBuckets[$key] = 0.0;
    }

    $categoryTotals = [];

    foreach ($expenses as $expense) {
        $amount = (float) $expense['amount'];
        $stats['totalExpenses'] += $amount;
        $stats['expenseCount']++;

        if (month_key((string) $expense['spent_on']) === $currentMonth) {
            $stats['monthlyTotal'] += $amount;
        }

        $expenseMonth = month_key((string) $expense['spent_on']);
        if (array_key_exists($expenseMonth, $monthBuckets)) {
            $monthBuckets[$expenseMonth] += $amount;
        }

        $category = (string) $expense['category'];
        $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $amount;

        if (!isset($expenseGroups[$expenseMonth])) {
            $expenseGroups[$expenseMonth] = [
                'label' => month_label_from_key($expenseMonth),
                'total' => 0.0,
                'items' => [],
            ];
        }

        $expenseGroups[$expenseMonth]['total'] += $amount;
        $expenseGroups[$expenseMonth]['items'][] = $expense;
    }

    foreach ($todos as $todo) {
        $dueDate = (string) $todo['due_date'];

        if ($todo['status'] === 'done') {
            $stats['completedTodos']++;
        } else {
            $stats['pendingTodos']++;
            $pendingTodos[] = $todo;

            if ($dueDate !== '' && $dueDate < $today) {
                $stats['overdueTodos']++;
            }

            if ($dueDate !== '' && $dueDate >= $today && $dueDate <= date('Y-m-d', strtotime('+7 days'))) {
                $upcomingTodos[] = $todo;
            }
        }
    }

    $totalTodos = count($todos);
    $stats['completionRate'] = $totalTodos > 0 ? (int) round(($stats['completedTodos'] / $totalTodos) * 100) : 0;
    $stats['noteCount'] = count($notes);

    foreach ($monthBuckets as $month => $value) {
        $monthlyChart[] = [
            'label' => date('m/Y', strtotime($month . '-01')),
            'value' => round($value, 2),
        ];
    }

    arsort($categoryTotals);
    foreach (array_slice($categoryTotals, 0, 6, true) as $category => $value) {
        $categoryChart[] = [
            'label' => $category,
            'value' => round($value, 2),
        ];
    }

    $todoChart = [
        ['label' => 'Đang làm', 'value' => $stats['pendingTodos']],
        ['label' => 'Hoàn thành', 'value' => $stats['completedTodos']],
    ];

    $topCategory = $categoryChart[0] ?? null;
    if ($topCategory !== null) {
        $insightText = 'Nhóm chi tiêu cao nhất hiện tại là "' . $topCategory['label'] . '" với tổng ' . format_money((float) $topCategory['value']) . '.';
    }

    $recentExpenses = array_slice($expenses, 0, 5);
    $recentNotes = array_slice($notes, 0, 4);
    $pendingTodos = array_slice($pendingTodos, 0, 6);
    $upcomingTodos = array_slice($upcomingTodos, 0, 5);

    if ($currentPage === 'expenses' && isset($_GET['edit_expense'])) {
        $editingExpense = fetch_one_assoc($db, 'SELECT * FROM expenses WHERE id = :id', [':id' => (int) $_GET['edit_expense']]);
    }

    if ($currentPage === 'todos' && isset($_GET['edit_todo'])) {
        $editingTodo = fetch_one_assoc($db, 'SELECT * FROM todos WHERE id = :id', [':id' => (int) $_GET['edit_todo']]);
    }

    if ($currentPage === 'notes' && isset($_GET['edit_note'])) {
        $editingNote = fetch_one_assoc($db, 'SELECT * FROM notes WHERE id = :id', [':id' => (int) $_GET['edit_note']]);
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> | <?= e($pageTitles[$currentPage]) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand-card">
                <span class="brand-card__badge">Đồ án PTTK-HTTT</span>
                <h1><?= e($appName) ?></h1>
                <p>Ứng dụng quản lý cá nhân với giao diện dashboard, lưu dữ liệu local bằng SQLite.</p>
            </div>

            <nav class="sidebar-nav">
                <a href="index.php?page=dashboard" class="<?= $currentPage === 'dashboard' ? 'is-active' : '' ?>">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h7V4H4zm9 7h7v-9h-7zM4 20h7v-5H4zm9-9h7V4h-7z"></path></svg>
                    </span>
                    <span>Tổng quan</span>
                </a>
                <a href="index.php?page=expenses" class="<?= $currentPage === 'expenses' ? 'is-active' : '' ?>">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v4H5zm0 6h14v10H5zm3 3v2h4v-2zm0 3.5V18h8v-1.5z"></path></svg>
                    </span>
                    <span>Chi tiêu</span>
                </a>
                <a href="index.php?page=todos" class="<?= $currentPage === 'todos' ? 'is-active' : '' ?>">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 17.2 4.8 13l1.4-1.4L9 14.4l8.8-8.8L19.2 7zM4 5h3v3H4zm0 11h3v3H4zm6-11h10v3H10zm0 11h10v3H10z"></path></svg>
                    </span>
                    <span>Công việc</span>
                </a>
                <a href="index.php?page=notes" class="<?= $currentPage === 'notes' ? 'is-active' : '' ?>">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l5 5v13H6zm8 1.5V9h4.5zM8 12h8v1.8H8zm0 3.8h8v1.8H8z"></path></svg>
                    </span>
                    <span>Ghi chú</span>
                </a>
                <a href="index.php?page=qr" class="<?= $currentPage === 'qr' ? 'is-active' : '' ?>">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zm10 0h6v6h-6zM4 14h6v6H4zm2 2v2h2v-2zm10-2h2v2h2v4h-6v-2h2zm-2 0h2v2h-2zm4 4h2v2h-2z"></path></svg>
                    </span>
                    <span>Tạo mã QR</span>
                </a>
                <a href="index.php?page=reports" class="<?= $currentPage === 'reports' ? 'is-active' : '' ?>">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19h14v2H5zm1-3h2V8H6zm5 0h2V4h-2zm5 0h2v-6h-2z"></path></svg>
                    </span>
                    <span>Báo cáo</span>
                </a>
            </nav>

           
        </aside>

        <main class="main-content">
            <div class="ticker">
                <div class="ticker__track">
                    <span>Đồ án môn học PTTK-HTTT • Ứng dụng quản lý cá nhân LifeBoard • Quản lý chi tiêu, công việc, ghi chú, biểu đồ và báo cáo trực quan</span>
                    <span>Đồ án môn học PTTK-HTTT • Ứng dụng quản lý cá nhân LifeBoard • Quản lý chi tiêu, công việc, ghi chú, biểu đồ và báo cáo trực quan</span>
                </div>
            </div>

            <header class="page-header">
                <div>
                    <span class="page-header__eyebrow">Dashboard quản lý cá nhân</span>
                    <h2><?= e($pageTitles[$currentPage]) ?></h2>
                    <p><?= e($pageDescriptions[$currentPage]) ?></p>
                </div>
                <div class="page-header__meta">
                    <button id="themeToggleButton" type="button" class="theme-toggle" aria-pressed="false" aria-label="Chuyển chế độ sáng tối">
                        <span class="theme-toggle__icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2 14 7l4.2.6-3 2.9.7 4.1-3.9-2.1-3.9 2.1.7-4.1-3-2.9L10 7zm0 11.3a5.5 5.5 0 1 0 5.5 5.5A5.5 5.5 0 0 0 12 14.5z"></path></svg>
                        </span>
                        <span id="themeToggleLabel">Chế độ tối</span>
                    </button>
                    <span>Hôm nay: <?= e(date('d/m/Y')) ?></span>
                    <span>Dữ liệu lưu local trên máy</span>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="alert alert--<?= e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if ($setupError !== null): ?>
                <div class="alert alert--error">
                    <?= e($setupError) ?>
                </div>
            <?php endif; ?>

            <section class="stats-grid">
                <article class="stat-card">
                    <span>Tổng chi tháng này</span>
                    <strong><?= format_money((float) $stats['monthlyTotal']) ?></strong>
                    <p>Theo dõi nhanh ngân sách chi tiêu trong tháng hiện tại.</p>
                </article>
                <article class="stat-card">
                    <span>Công việc đang làm</span>
                    <strong><?= e((string) $stats['pendingTodos']) ?></strong>
                    <p>Số lượng task chưa hoàn thành cần ưu tiên xử lý.</p>
                </article>
                <article class="stat-card">
                    <span>Tỷ lệ hoàn thành</span>
                    <strong><?= e((string) $stats['completionRate']) ?>%</strong>
                    <p>Chỉ số phù hợp để trình bày hiệu quả sử dụng ứng dụng.</p>
                </article>
                <article class="stat-card">
                    <span>Ghi chú đã lưu</span>
                    <strong><?= e((string) $stats['noteCount']) ?></strong>
                    <p>Tập trung ý tưởng, bài học và các thông tin cần nhớ.</p>
                </article>
            </section>

            <?php if ($currentPage === 'dashboard'): ?>
                <section class="content-grid">
                    <article class="panel panel--wide">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Biểu đồ nổi bật</span>
                                <h3>Tổng quan chi tiêu và phân bổ danh mục</h3>
                            </div>
                            <a class="text-link" href="index.php?page=reports">Xem báo cáo chi tiết</a>
                        </div>
                        <div class="charts-grid charts-grid--triple">
                            <div class="chart-card">
                                <div class="chart-card__head">
                                    <h4>Chi tiêu 6 tháng gần đây</h4>
                                    <span>Biểu đồ cột</span>
                                </div>
                                <canvas id="monthlyChart" height="250"></canvas>
                            </div>
                            <div class="chart-card">
                                <div class="chart-card__head">
                                    <h4>Tỷ trọng chi tiêu</h4>
                                    <span>Biểu đồ tròn</span>
                                </div>
                                <div class="pie-chart-box">
                                    <canvas id="expensePieChart" height="240"></canvas>
                                    <div id="expensePieLegend" class="pie-legend"></div>
                                </div>
                            </div>
                            <div class="chart-card">
                                <div class="chart-card__head">
                                    <h4>Danh mục chi tiêu</h4>
                                    <span>Top 6 nhóm</span>
                                </div>
                                <div id="categoryChart" class="category-chart"></div>
                            </div>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Phân tích nhanh</span>
                                <h3>Điểm nhấn để thuyết trình</h3>
                            </div>
                        </div>
                        <div class="highlight-box">
                            <strong>Insight nổi bật</strong>
                            <p><?= e($insightText) ?></p>
                        </div>
                        <ul class="simple-list">
                            <li>Tổng khoản chi đã lưu: <?= e((string) $stats['expenseCount']) ?></li>
                            <li>Công việc trễ hạn: <?= e((string) $stats['overdueTodos']) ?></li>
                            <li>Dữ liệu được lưu local bằng SQLite</li>
                           
                        </ul>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Hoạt động mới</span>
                                <h3>Khoản chi gần đây</h3>
                            </div>
                            <a class="text-link" href="index.php?page=expenses">Quản lý chi tiêu</a>
                        </div>
                        <div class="activity-list">
                            <?php if ($recentExpenses === []): ?>
                                <div class="empty-state">Chưa có dữ liệu chi tiêu. Hãy thêm vài khoản để biểu đồ và lịch sử hiển thị đẹp hơn.</div>
                            <?php else: ?>
                                <?php foreach ($recentExpenses as $expense): ?>
                                    <article class="activity-item">
                                        <div>
                                            <strong><?= e($expense['category']) ?></strong>
                                            <p><?= e($expense['description'] ?: 'Không có ghi chú') ?></p>
                                        </div>
                                        <div class="activity-item__meta">
                                            <span><?= format_money((float) $expense['amount']) ?></span>
                                            <small><?= e(date('d/m/Y', strtotime((string) $expense['spent_on']))) ?></small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Theo dõi công việc</span>
                                <h3>Việc cần làm sắp tới</h3>
                            </div>
                            <a class="text-link" href="index.php?page=todos">Xem tất cả</a>
                        </div>
                        <div class="activity-list">
                            <?php if ($pendingTodos === []): ?>
                                <div class="empty-state">Chưa có công việc nào đang mở.</div>
                            <?php else: ?>
                                <?php foreach ($pendingTodos as $todo): ?>
                                    <article class="activity-item">
                                        <div>
                                            <strong><?= e($todo['title']) ?></strong>
                                            <p>Ưu tiên: <?= e($todo['priority']) ?></p>
                                        </div>
                                        <div class="activity-item__meta">
                                            <span class="status-pill status-pill--pending">Đang làm</span>
                                            <small><?= $todo['due_date'] ? e(date('d/m/Y', strtotime((string) $todo['due_date']))) : 'Chưa đặt hạn' ?></small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                </section>
            <?php endif; ?>

            <?php if ($currentPage === 'expenses'): ?>
                <section class="content-grid content-grid--management">
                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Biểu mẫu</span>
                                <h3><?= $editingExpense ? 'Sửa khoản chi' : 'Thêm khoản chi' ?></h3>
                            </div>
                            <?php if ($editingExpense): ?>
                                <a class="text-link" href="index.php?page=expenses">Tạo mới</a>
                            <?php endif; ?>
                        </div>

                        <form method="post" class="data-form">
                            <input type="hidden" name="action" value="save_expense">
                            <input type="hidden" name="redirect_page" value="expenses">
                            <input type="hidden" name="expense_id" value="<?= e((string) ($editingExpense['id'] ?? 0)) ?>">

                            <label>
                                <span>Số tiền</span>
                                <input type="number" name="amount" min="1000" step="1000" value="<?= e((string) ($editingExpense['amount'] ?? '')) ?>" placeholder="Ví dụ: 50000" required>
                            </label>

                            <label>
                                <span>Danh mục</span>
                                <select name="category" required>
                                    <?php foreach ($expenseCategories as $category): ?>
                                        <option value="<?= e($category) ?>" <?= (($editingExpense['category'] ?? '') === $category) ? 'selected' : '' ?>><?= e($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                <span>Ngày chi</span>
                                <input type="date" name="spent_on" value="<?= e((string) ($editingExpense['spent_on'] ?? date('Y-m-d'))) ?>" required>
                            </label>

                            <label class="field--full">
                                <span>Mô tả</span>
                                <input type="text" name="description" value="<?= e((string) ($editingExpense['description'] ?? '')) ?>" placeholder="Cà phê, photo tài liệu, mua sách...">
                            </label>

                            <button type="submit" class="button button--primary"><?= $editingExpense ? 'Cập nhật khoản chi' : 'Lưu khoản chi' ?></button>
                        </form>

                        <div class="info-strip">
                            <span>Tổng chi tiêu: <?= format_money((float) $stats['totalExpenses']) ?></span>
                            <span>Tháng này: <?= format_money((float) $stats['monthlyTotal']) ?></span>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Lịch sử chi tiêu</span>
                                <h3>Phân tách theo từng tháng</h3>
                            </div>
                            <span class="panel__summary"><?= e((string) $stats['expenseCount']) ?> khoản chi</span>
                        </div>

                        <div class="expense-history">
                            <?php if ($expenseGroups === []): ?>
                                <div class="empty-state">Chưa có khoản chi nào được lưu.</div>
                            <?php else: ?>
                                <?php foreach ($expenseGroups as $group): ?>
                                    <section class="expense-month">
                                        <div class="month-divider">
                                            <span><?= e($group['label']) ?></span>
                                            <strong><?= format_money((float) $group['total']) ?></strong>
                                        </div>

                                        <div class="expense-list">
                                            <?php foreach ($group['items'] as $expense): ?>
                                                <article class="expense-item">
                                                    <div class="expense-item__main">
                                                        <strong><?= e($expense['category']) ?></strong>
                                                        <p><?= e($expense['description'] ?: 'Không có mô tả') ?></p>
                                                    </div>
                                                    <div class="expense-item__date">
                                                        <span><?= e(date('d/m/Y', strtotime((string) $expense['spent_on']))) ?></span>
                                                        <small><?= format_money((float) $expense['amount']) ?></small>
                                                    </div>
                                                    <div class="table-actions">
                                                        <a class="button button--small" href="index.php?page=expenses&amp;edit_expense=<?= e((string) $expense['id']) ?>">Sửa</a>
                                                        <form method="post">
                                                            <input type="hidden" name="action" value="delete_expense">
                                                            <input type="hidden" name="id" value="<?= e((string) $expense['id']) ?>">
                                                            <input type="hidden" name="redirect_page" value="expenses">
                                                            <button type="submit" class="button button--danger">Xóa</button>
                                                        </form>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                </section>
            <?php endif; ?>

            <?php if ($currentPage === 'todos'): ?>
                <section class="content-grid content-grid--management">
                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Biểu mẫu</span>
                                <h3><?= $editingTodo ? 'Sửa công việc' : 'Thêm công việc' ?></h3>
                            </div>
                            <?php if ($editingTodo): ?>
                                <a class="text-link" href="index.php?page=todos">Tạo mới</a>
                            <?php endif; ?>
                        </div>

                        <form method="post" class="data-form">
                            <input type="hidden" name="action" value="save_todo">
                            <input type="hidden" name="redirect_page" value="todos">
                            <input type="hidden" name="todo_id" value="<?= e((string) ($editingTodo['id'] ?? 0)) ?>">

                            <label class="field--full">
                                <span>Tên công việc</span>
                                <input type="text" name="title" value="<?= e((string) ($editingTodo['title'] ?? '')) ?>" placeholder="Ví dụ: Hoàn thành slide báo cáo" required>
                            </label>

                            <label>
                                <span>Hạn xử lý</span>
                                <input type="date" name="due_date" value="<?= e((string) ($editingTodo['due_date'] ?? '')) ?>">
                            </label>

                            <label>
                                <span>Mức ưu tiên</span>
                                <select name="priority">
                                    <?php foreach ($todoPriorities as $priority): ?>
                                        <option value="<?= e($priority) ?>" <?= (($editingTodo['priority'] ?? 'Trung bình') === $priority) ? 'selected' : '' ?>><?= e($priority) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                <span>Trạng thái</span>
                                <select name="status">
                                    <?php foreach ($todoStatuses as $statusKey => $statusLabel): ?>
                                        <option value="<?= e($statusKey) ?>" <?= (($editingTodo['status'] ?? 'pending') === $statusKey) ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <button type="submit" class="button button--primary"><?= $editingTodo ? 'Cập nhật công việc' : 'Lưu công việc' ?></button>
                        </form>

                        <div class="info-strip">
                            <span>Công việc trễ hạn: <?= e((string) $stats['overdueTodos']) ?></span>
                            <span>Công việc hoàn thành: <?= e((string) $stats['completedTodos']) ?></span>
                        </div>
                    </article>

                    <article class="panel panel--table">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Danh sách</span>
                                <h3>Quản lý to-do</h3>
                            </div>
                            <span class="panel__summary">Tổng số: <?= e((string) count($todos)) ?> công việc</span>
                        </div>

                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Công việc</th>
                                        <th>Ưu tiên</th>
                                        <th>Hạn</th>
                                        <th>Trạng thái</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($todos === []): ?>
                                        <tr>
                                            <td colspan="5" class="table-empty">Chưa có công việc nào.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($todos as $todo): ?>
                                            <tr>
                                                <td><?= e($todo['title']) ?></td>
                                                <td><?= e($todo['priority']) ?></td>
                                                <td><?= $todo['due_date'] ? e(date('d/m/Y', strtotime((string) $todo['due_date']))) : 'Chưa đặt hạn' ?></td>
                                                <td>
                                                    <span class="status-pill <?= $todo['status'] === 'done' ? 'status-pill--done' : 'status-pill--pending' ?>">
                                                        <?= e($todoStatuses[$todo['status']] ?? 'Đang làm') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="table-actions">
                                                        <form method="post">
                                                            <input type="hidden" name="action" value="toggle_todo">
                                                            <input type="hidden" name="id" value="<?= e((string) $todo['id']) ?>">
                                                            <input type="hidden" name="current_status" value="<?= e((string) $todo['status']) ?>">
                                                            <input type="hidden" name="redirect_page" value="todos">
                                                            <button type="submit" class="button button--small"><?= $todo['status'] === 'done' ? 'Mở lại' : 'Hoàn thành' ?></button>
                                                        </form>
                                                        <a class="button button--small" href="index.php?page=todos&amp;edit_todo=<?= e((string) $todo['id']) ?>">Sửa</a>
                                                        <form method="post">
                                                            <input type="hidden" name="action" value="delete_todo">
                                                            <input type="hidden" name="id" value="<?= e((string) $todo['id']) ?>">
                                                            <input type="hidden" name="redirect_page" value="todos">
                                                            <button type="submit" class="button button--danger">Xóa</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>
            <?php endif; ?>

            <?php if ($currentPage === 'notes'): ?>
                <section class="content-grid content-grid--management">
                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Biểu mẫu</span>
                                <h3><?= $editingNote ? 'Sửa ghi chú' : 'Thêm ghi chú' ?></h3>
                            </div>
                            <?php if ($editingNote): ?>
                                <a class="text-link" href="index.php?page=notes">Tạo mới</a>
                            <?php endif; ?>
                        </div>

                        <form method="post" class="data-form">
                            <input type="hidden" name="action" value="save_note">
                            <input type="hidden" name="redirect_page" value="notes">
                            <input type="hidden" name="note_id" value="<?= e((string) ($editingNote['id'] ?? 0)) ?>">

                            <label>
                                <span>Tiêu đề</span>
                                <input type="text" name="title" value="<?= e((string) ($editingNote['title'] ?? '')) ?>" placeholder="Ví dụ: Ý tưởng cải tiến ứng dụng" required>
                            </label>

                            <label>
                                <span>Phân loại</span>
                                <input type="text" name="mood" value="<?= e((string) ($editingNote['mood'] ?? '')) ?>" placeholder="Học tập, họp nhóm, việc cá nhân...">
                            </label>

                            <label class="field--full">
                                <span>Nội dung</span>
                                <textarea name="content" rows="6" placeholder="Nhập nội dung ghi chú..." required><?= e((string) ($editingNote['content'] ?? '')) ?></textarea>
                            </label>

                            <button type="submit" class="button button--primary"><?= $editingNote ? 'Cập nhật ghi chú' : 'Lưu ghi chú' ?></button>
                        </form>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Danh sách</span>
                                <h3>Kho ghi chú</h3>
                            </div>
                            <span class="panel__summary"><?= e((string) $stats['noteCount']) ?> ghi chú</span>
                        </div>

                        <div class="note-grid">
                            <?php if ($notes === []): ?>
                                <div class="empty-state">Chưa có ghi chú nào được tạo.</div>
                            <?php else: ?>
                                <?php foreach ($notes as $note): ?>
                                    <article class="note-card">
                                        <div class="note-card__top">
                                            <strong><?= e($note['title']) ?></strong>
                                            <?php if ((string) $note['mood'] !== ''): ?>
                                                <span><?= e($note['mood']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <p><?= nl2br(e($note['content'])) ?></p>
                                        <div class="note-card__footer">
                                            <small><?= e(date('d/m/Y H:i', strtotime((string) $note['created_at']))) ?></small>
                                            <div class="table-actions">
                                                <a class="button button--small" href="index.php?page=notes&amp;edit_note=<?= e((string) $note['id']) ?>">Sửa</a>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="delete_note">
                                                    <input type="hidden" name="id" value="<?= e((string) $note['id']) ?>">
                                                    <input type="hidden" name="redirect_page" value="notes">
                                                    <button type="submit" class="button button--danger">Xóa</button>
                                                </form>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                </section>
            <?php endif; ?>

            <?php if ($currentPage === 'qr'): ?>
                <section class="content-grid content-grid--management">
                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">QR Generator</span>
                                <h3>Tạo mã QR số điện thoại offline</h3>
                            </div>
                        </div>

                        <form id="offlineQrForm" class="data-form" autocomplete="off">
                            <label>
                                <span>Số điện thoại</span>
                                <input id="qrPhoneInput" type="tel" inputmode="tel" placeholder="Ví dụ: 0901234567 hoặc +84901234567" value="">
                            </label>

                            <label>
                                <span>Kích thước QR</span>
                                <select id="qrSizeInput">
                                    <option value="180">180 x 180</option>
                                    <option value="220">220 x 220</option>
                                    <option value="260" selected>260 x 260</option>
                                    <option value="320">320 x 320</option>
                                </select>
                            </label>

                            <label class="field--full">
                                <span>Dữ liệu QR sẽ mã hóa</span>
                                <input id="qrPayloadPreview" type="text" value="TEL:0901234567" readonly>
                            </label>

                            <div class="qr-actions">
                                <button type="submit" class="button button--primary">Tạo mã QR</button>
                                <button id="qrSampleButton" type="button" class="button button--small">Điền số mẫu</button>
                            </div>
                        </form>

                        <div class="info-strip">
                            <span>Tính năng này chạy offline ngay trong trình duyệt, không gọi website tạo QR bên ngoài.</span>
                            <span>Mã QR dùng chuẩn <code>TEL:</code>, khi quét trên điện thoại sẽ hiện số để gọi hoặc lưu liên hệ nhanh.</span>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Xem trước</span>
                                <h3>Mã QR điện thoại</h3>
                            </div>
                        </div>

                        <div class="qr-generator-card">
                            <canvas id="offlineQrCanvas" width="260" height="260" aria-label="Mã QR số điện thoại"></canvas>
                            <div class="qr-generator-card__content">
                                <strong>Nội dung đang mã hóa</strong>
                                <p id="qrReadableText">Nhập số điện thoại rồi bấm tạo mã QR.</p>
                                <p id="qrStatusText">Ứng dụng sẽ tạo mã QR offline trực tiếp trên máy của bạn.</p>
                                <div class="table-actions">
                                    <button id="qrDownloadButton" type="button" class="button button--primary">Tải QR PNG</button>
                                    <button id="qrClearButton" type="button" class="button button--small">Xóa nội dung</button>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>
            <?php endif; ?>

            <?php if ($currentPage === 'reports'): ?>
                <section class="content-grid">
                    <article class="panel panel--wide">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Báo cáo</span>
                                <h3>Biểu đồ phục vụ thuyết trình</h3>
                            </div>
                        </div>
                        <div class="charts-grid charts-grid--reports">
                            <div class="chart-card">
                                <div class="chart-card__head">
                                    <h4>Xu hướng chi tiêu theo tháng</h4>
                                    <span>6 tháng gần nhất</span>
                                </div>
                                <canvas id="monthlyChart" height="280"></canvas>
                            </div>
                            <div class="chart-card">
                                <div class="chart-card__head">
                                    <h4>Biểu đồ tròn danh mục</h4>
                                    <span>Phần trăm từng nhóm chi</span>
                                </div>
                                <div class="pie-chart-box">
                                    <canvas id="expensePieChart" height="260"></canvas>
                                    <div id="expensePieLegend" class="pie-legend"></div>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Hiệu suất</span>
                                <h3>Tiến độ công việc</h3>
                            </div>
                        </div>
                        <div id="todoChart" class="todo-chart"></div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Nhận xét</span>
                                <h3>Điểm nhấn để báo cáo</h3>
                            </div>
                        </div>
                        <ul class="simple-list">
                            <li>Ứng dụng quản lý chi tiêu trong đời sống hằng ngày.</li>
                            <li>Dữ liệu được lưu local bằng SQLite.</li>
                            <li>Biểu đồ tròn giúp nhìn rõ phần trăm chi tiêu theo từng nhóm.</li>
                            <li>Lịch sử chi tiêu đã được chia theo tháng để dễ quan sát .</li>
                        </ul>

                        <div class="highlight-box">
                            <strong>Nhóm chi tiêu nổi bật</strong>
                            <p><?= e($topCategory ? $topCategory['label'] . ' - ' . format_money((float) $topCategory['value']) : 'Chưa có dữ liệu') ?></p>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel__header">
                            <div>
                                <span class="panel__eyebrow">Theo lịch</span>
                                <h3>Công việc đến hạn trong 7 ngày</h3>
                            </div>
                        </div>
                        <div class="activity-list">
                            <?php if ($upcomingTodos === []): ?>
                                <div class="empty-state">Không có công việc nào đến hạn trong 7 ngày tới.</div>
                            <?php else: ?>
                                <?php foreach ($upcomingTodos as $todo): ?>
                                    <article class="activity-item">
                                        <div>
                                            <strong><?= e($todo['title']) ?></strong>
                                            <p>Ưu tiên: <?= e($todo['priority']) ?></p>
                                        </div>
                                        <div class="activity-item__meta">
                                            <span class="status-pill status-pill--pending">Đang làm</span>
                                            <small><?= e(date('d/m/Y', strtotime((string) $todo['due_date']))) ?></small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                </section>
            <?php endif; ?>

            <footer class="app-footer">
                <button type="button" class="footer-badge">© 2026 Quản lý chi tiêu</button>
                <p>LifeBoard • Đồ án môn học PTTK-HTTT • Giao diện dashboard nhiều màu, thống kê quản lý chi tiêu cá nhân offline.</p>
            </footer>
        </main>
    </div>

    <script>
        window.dashboardData = {
            monthly: <?= json_encode($monthlyChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            categories: <?= json_encode($categoryChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            todos: <?= json_encode($todoChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
    </script>
    <script src="assets/app.js"></script>
</body>
</html>
