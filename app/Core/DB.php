<?php
declare(strict_types=1);

namespace Core;

use PDO;

/**
 * The only way into MySQL. The app user has EXECUTE and nothing else (see
 * database/setup.sql), so anything that is not a stored procedure call would
 * fail at the server anyway — the name check here just turns that into a clear
 * error instead of an access-denied.
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static ?PDO $sessionPdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /**
     * A SECOND connection, used only by Core\Session.
     *
     * This separation is not tidiness, it is correctness. The session handler
     * holds a row lock inside a transaction from read to write — for the whole
     * request. On a shared connection that transaction encloses every
     * application query too, so a rollback meant to release the session lock
     * silently discards the page the request just created. That happened, and
     * it looked like "saving reports success but nothing is there".
     *
     * On its own connection the session transaction can begin, commit or roll
     * back whenever it likes without touching application data.
     */
    public static function sessionPdo(): PDO
    {
        return self::$sessionPdo ??= self::connect();
    }

    private static function connect(): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci",
        ]);
    }

    /** CALL a stored procedure, return the first result set as rows. */
    public static function proc(string $name, array $args = []): array
    {
        $stmt = self::run($name, $args);
        $rows = $stmt->fetchAll() ?: [];
        // drain remaining result sets so the connection is reusable
        while ($stmt->nextRowset()) {}
        $stmt->closeCursor();
        return $rows;
    }

    /** CALL a procedure, return ALL result sets (array of row arrays). */
    public static function procMulti(string $name, array $args = []): array
    {
        $stmt = self::run($name, $args);
        $sets = [];
        do {
            $sets[] = $stmt->fetchAll() ?: [];
        } while ($stmt->nextRowset());
        $stmt->closeCursor();
        return $sets;
    }

    /** First row of the first result set, or null. */
    public static function procOne(string $name, array $args = []): ?array
    {
        $rows = self::proc($name, $args);
        return $rows[0] ?? null;
    }

    /** Fire-and-forget: a procedure that returns nothing. */
    public static function exec(string $name, array $args = []): void
    {
        $stmt = self::run($name, $args);
        while ($stmt->nextRowset()) {}
        $stmt->closeCursor();
    }

    /** Same as proc(), on the session connection. Only Core\Session should use these. */
    public static function sessionProcOne(string $name, array $args = []): ?array
    {
        $stmt = self::run($name, $args, self::sessionPdo());
        $rows = $stmt->fetchAll() ?: [];
        while ($stmt->nextRowset()) {}
        $stmt->closeCursor();
        return $rows[0] ?? null;
    }

    public static function sessionExec(string $name, array $args = []): void
    {
        $stmt = self::run($name, $args, self::sessionPdo());
        while ($stmt->nextRowset()) {}
        $stmt->closeCursor();
    }

    private static function run(string $name, array $args, ?PDO $on = null): \PDOStatement
    {
        if (!preg_match('/^sp_[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid procedure name');
        }
        $placeholders = implode(',', array_fill(0, count($args), '?'));
        $stmt = ($on ?? self::pdo())->prepare("CALL $name($placeholders)");
        $stmt->execute(array_values($args));
        return $stmt;
    }
}
