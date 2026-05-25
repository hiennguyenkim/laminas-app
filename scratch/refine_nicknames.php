<?php
/**
 * Data Sanitization - Realistic Nicknames
 * Goal: Update existing users with realistic nicknames. Some users will have NO nickname.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Refining Nicknames...\n";

    $users = $pdo->query("SELECT user_id, full_name, username FROM users WHERE role = 'student'")->fetchAll();
    
    $stmtUpdate = $pdo->prepare("UPDATE users SET nickname = ? WHERE user_id = ?");
    
    // Adjectives or common nickname suffixes in VN
    $nickSuffixes = ['Lười', 'Chăm Chỉ', 'Cute', 'Cool', 'Mọt Sách', 'Coder', 'Developer', 'Designer', 'Pro', 'Max', 'Kun', 'Chan'];
    
    $updatedCount = 0;
    $nullCount = 0;

    foreach ($users as $u) {
        // 40% chance of NOT having a nickname
        if (rand(1, 100) <= 40) {
            $stmtUpdate->execute([null, $u['user_id']]);
            $nullCount++;
            continue;
        }

        // Generate realistic nickname
        $nameParts = explode(' ', $u['full_name']);
        $firstName = end($nameParts); // Vietnamese first name is usually the last word
        
        $nickType = rand(1, 3);
        if ($nickType === 1) {
            // e.g., "Anh Lười", "Mai Cute"
            $nickname = $firstName . ' ' . $nickSuffixes[array_rand($nickSuffixes)];
        } elseif ($nickType === 2) {
            // e.g., "anh.nguyen", "mai.tran" (based on full name parts)
            $lastName = $nameParts[0];
            $nickname = strtolower($firstName . '.' . $lastName . rand(99, 999));
            // Basic remove accents for simple nick
            $nickname = str_replace(
                ['á','à','ả','ã','ạ','ă','ắ','ằ','ẳ','ẵ','ặ','â','ấ','ầ','ẩ','ẫ','ậ'], 'a', $nickname
            );
            $nickname = str_replace(['é','è','ẻ','ẽ','ẹ','ê','ế','ề','ể','ễ','ệ'], 'e', $nickname);
            $nickname = str_replace(['í','ì','ỉ','ĩ','ị'], 'i', $nickname);
            $nickname = str_replace(['ó','ò','ỏ','õ','ọ','ô','ố','ồ','ổ','ỗ','ộ','ơ','ớ','ờ','ở','ỡ','ợ'], 'o', $nickname);
            $nickname = str_replace(['ú','ù','ủ','ũ','ụ','ư','ứ','ừ','ử','ữ','ự'], 'u', $nickname);
            $nickname = str_replace(['ý','ỳ','ỷ','ỹ','ỵ'], 'y', $nickname);
            $nickname = str_replace('đ', 'd', $nickname);
        } else {
            // Use their actual first name but doubled or cute form, e.g., "Tùng Tùng", "Bé Lan"
            $nickname = (rand(0,1) ? "Bé " : "") . $firstName . (rand(0,1) ? " $firstName" : "");
        }

        $stmtUpdate->execute([$nickname, $u['user_id']]);
        $updatedCount++;
    }

    echo "   -> Set realistic nicknames for $updatedCount students.\n";
    echo "   -> Removed nicknames (set to NULL) for $nullCount students.\n";
    echo "Nickname Refinement COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
