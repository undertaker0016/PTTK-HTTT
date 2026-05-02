<?php
declare(strict_types=1);

function initialize_database(): array
{
    if (!class_exists('SQLite3')) {
        return [
            null,
            'PHP của bạn chưa bật extension SQLite3. Hãy bật SQLite3 trong Laragon để ứng dụng hoạt động.',
        ];
    }

    try {
        $db = new SQLite3(__DIR__ . '/storage/personal_manager.sqlite');
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

        return [$db, null];
    } catch (Throwable $exception) {
        return [null, 'Không thể khởi tạo SQLite: ' . $exception->getMessage()];
    }
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

function get_expenses(SQLite3 $db): array
{
    return fetch_all_assoc($db->query('SELECT * FROM expenses ORDER BY spent_on DESC, id DESC'));
}

function get_todos(SQLite3 $db): array
{
    return fetch_all_assoc(
        $db->query(
            'SELECT * FROM todos
             ORDER BY CASE WHEN status = "pending" THEN 0 ELSE 1 END, due_date IS NULL, due_date ASC, id DESC'
        )
    );
}

function get_notes(SQLite3 $db): array
{
    return fetch_all_assoc($db->query('SELECT * FROM notes ORDER BY id DESC'));
}

function find_expense(SQLite3 $db, int $id): ?array
{
    return fetch_one_assoc($db, 'SELECT * FROM expenses WHERE id = :id', [':id' => $id]);
}

function find_todo(SQLite3 $db, int $id): ?array
{
    return fetch_one_assoc($db, 'SELECT * FROM todos WHERE id = :id', [':id' => $id]);
}

function find_note(SQLite3 $db, int $id): ?array
{
    return fetch_one_assoc($db, 'SELECT * FROM notes WHERE id = :id', [':id' => $id]);
}

function save_expense(SQLite3 $db, int $expenseId, float $amount, string $category, string $description, string $spentOn): void
{
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
}

function delete_expense(SQLite3 $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM expenses WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function save_todo(SQLite3 $db, int $todoId, string $title, string $dueDate, string $priority, string $status): void
{
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
}

function toggle_todo_status(SQLite3 $db, int $id, string $currentStatus): void
{
    $nextStatus = $currentStatus === 'done' ? 'pending' : 'done';

    $stmt = $db->prepare('UPDATE todos SET status = :status WHERE id = :id');
    $stmt->bindValue(':status', $nextStatus, SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function delete_todo(SQLite3 $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM todos WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function save_note(SQLite3 $db, int $noteId, string $title, string $content, string $mood): void
{
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
}

function delete_note(SQLite3 $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM notes WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}
