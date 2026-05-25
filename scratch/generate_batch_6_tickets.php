<?php
/**
 * Data Generation Script - Batch 6/10 (Focus: Realistic Support Tickets & Messages)
 * Goal: Clean up old generic tickets, generate 40 realistic tickets with conversation threads.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Batch 6: Refining Support Tickets...\n";

    // 1. Delete generic tickets from previous batch
    $pdo->query("DELETE FROM support_tickets WHERE description LIKE '%mong thủ thư hỗ trợ giúp em%'");
    echo "Deleted old generic tickets.\n";

    $students = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);
    $adminId = 1;

    $stmtTicket = $pdo->prepare("INSERT INTO support_tickets (user_id, category, title, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtMsg = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, ?, ?, ?)");

    $scenarios = [
        [
            'cat' => 'lost_item',
            'title' => 'Báo mất thẻ thư viện',
            'desc' => 'Dạ thưa thầy/cô, hôm qua em có làm rơi ví và mất luôn thẻ thư viện. Cho em hỏi thủ tục làm lại thẻ cần những gì ạ?',
            'admin_reply' => 'Chào em, để làm lại thẻ em mang thẻ sinh viên và CMND/CCCD đến quầy hỗ trợ tại tầng 1 vào giờ hành chính nhé. Phí cấp lại thẻ là 50.000đ.',
            'student_reply' => 'Dạ vâng, em cảm ơn thầy/cô ạ. Chiều nay em sẽ qua làm lại.'
        ],
        [
            'cat' => 'damaged_book',
            'title' => 'Sách mượn bị rách mất 2 trang',
            'desc' => 'Dạ, em vừa mượn cuốn "Giải tích 1" lúc sáng, nhưng mang về mở ra mới thấy trang 45 và 46 đã bị xé rách từ trước. Em báo lại để lúc trả không bị phạt ạ.',
            'admin_reply' => 'Chào em, cảm ơn em đã báo cáo. Thư viện đã ghi nhận tình trạng sách rách trang 45,46 của cuốn "Giải tích 1" em đang mượn. Em cứ yên tâm sử dụng nhé, sẽ không có hình phạt nào khi em trả sách.',
            'student_reply' => '' // No reply
        ],
        [
            'cat' => 'card_issue',
            'title' => 'Không thể gia hạn sách online',
            'desc' => 'Em có một cuốn sách sắp đến hạn trả, nhưng khi lên hệ thống OPAC để gia hạn thì nút gia hạn bị mờ không bấm được. Nhờ thư viện kiểm tra giúp em tài khoản này ạ.',
            'admin_reply' => 'Chào em, sách của em đã được gia hạn 1 lần trước đó rồi. Theo quy định, mỗi cuốn sách chỉ được gia hạn tối đa 1 lần. Em vui lòng mang sách đến trả đúng hạn nhé.',
            'student_reply' => 'À ra vậy, em không để ý quy định này. Em sẽ mang trả sớm ạ.'
        ],
        [
            'cat' => 'other',
            'title' => 'Hỏi về việc đề xuất mua sách mới',
            'desc' => 'Thư viện mình có cho phép sinh viên đề xuất mua sách chuyên ngành không ạ? Khoa em có mấy cuốn tham khảo tiếng Anh rất hay nhưng thư viện chưa có.',
            'admin_reply' => 'Chào em, thư viện luôn hoan nghênh các đề xuất từ sinh viên. Em có thể gửi danh sách tên sách, tác giả và nhà xuất bản qua form "Đề xuất sách" hoặc gửi trực tiếp email này nhé.',
            'student_reply' => 'Dạ em sẽ tổng hợp gửi email cho thư viện. Em cảm ơn!'
        ],
        [
            'cat' => 'card_issue',
            'title' => 'Tài khoản bị khóa vô cớ',
            'desc' => 'Dạ admin ơi, tự nhiên tài khoản của em bị báo là Khóa mượn sách. Em đã trả hết sách rồi mà ạ?',
            'admin_reply' => 'Chào em, hệ thống ghi nhận em đã trả sách cuốn "Đắc Nhân Tâm" trễ hẹn 5 ngày vào tuần trước nên tài khoản bị tạm khóa 7 ngày. Đến thứ Hai tuần sau tài khoản sẽ tự động mở lại nhé.',
            'student_reply' => ''
        ],
        [
            'cat' => 'lost_item',
            'title' => 'Làm mất sách mượn',
            'desc' => 'Thầy cô ơi, em sơ ý làm mất cuốn sách "Toán học cao cấp" mượn tuần trước. Em phải đền bù như thế nào ạ?',
            'admin_reply' => 'Trường hợp làm mất sách, em có 2 lựa chọn: 1 là mua một cuốn sách y hệt (cùng tên, cùng tác giả, NXB) để đền; 2 là đền bù gấp 3 lần giá bìa cuốn sách. Em đến quầy tầng 1 để giải quyết nhé.',
            'student_reply' => 'Dạ em chọn mua sách y hệt để đền ạ. Mai em mang qua.'
        ]
    ];

    $count = 0;
    for ($i = 0; $i < 40; $i++) {
        $uId = $students[array_rand($students)];
        $scenario = $scenarios[array_rand($scenarios)];
        
        // Randomize dates (last 30 days)
        $startTs = time() - rand(1, 30) * 86400;
        $created = date('Y-m-d H:i:s', $startTs);
        
        // Determine status and conversation length
        $randStat = rand(1, 100);
        if ($randStat < 20) {
            $status = 'open'; // Admin hasn't replied
            $updated = $created;
        } elseif ($randStat < 50) {
            $status = 'in_progress'; // Admin replied
            $updated = date('Y-m-d H:i:s', $startTs + rand(3600, 86400));
        } else {
            $status = 'closed'; // Solved
            $updated = date('Y-m-d H:i:s', $startTs + rand(86400, 86400*2));
        }

        $stmtTicket->execute([$uId, $scenario['cat'], $scenario['title'], $scenario['desc'], $status, $created, $updated]);
        $tId = $pdo->lastInsertId();

        // 1st Message: Student
        $stmtMsg->execute([$tId, $uId, 'user', $scenario['desc'], $created]);

        // 2nd Message: Admin (if not open)
        if ($status !== 'open') {
            $adminReplyTs = $startTs + rand(1800, 14400); // 30 mins to 4 hours later
            $stmtMsg->execute([$tId, $adminId, 'admin', $scenario['admin_reply'], date('Y-m-d H:i:s', $adminReplyTs)]);

            // 3rd Message: Student replies back (sometimes)
            if ($scenario['student_reply'] !== '' && rand(1, 100) > 30) {
                $stuReplyTs = $adminReplyTs + rand(1800, 7200);
                $stmtMsg->execute([$tId, $uId, 'user', $scenario['student_reply'], date('Y-m-d H:i:s', $stuReplyTs)]);
            }
        }
        $count++;
    }

    echo "Created $count realistic Support Tickets with conversation threads.\n";
    echo "Batch 6: COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
