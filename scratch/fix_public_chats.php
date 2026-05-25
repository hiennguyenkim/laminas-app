<?php
/**
 * Data Sanitization - Fix Public Chat Encodings and Add Realism
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Refining Public Chats...\n";

    // Delete existing corrupted chats
    $pdo->query("TRUNCATE TABLE public_chats");

    $students = $pdo->query("SELECT user_id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN);

    $realisticMessages = [
        "Thư viện hôm nay máy lạnh mát quá, ngồi học siêu tập trung luôn.",
        "Mọi người ơi, cuốn 'Đắc Nhân Tâm' có ai đang giữ không ạ? Bao giờ bạn trả cho mình mượn tiếp với.",
        "Lịch nghỉ lễ Quốc khánh thư viện có mở cửa buổi sáng không nhỉ?",
        "Hệ thống OPAC tra cứu sách mới mượt phết, nhanh hơn hẳn đợt trước.",
        "Ai có tài liệu môn Giải tích 2 không cho mình mượn photo với ạ.",
        "Sách mới về có cuốn AI nào hay không các bạn review mình với.",
        "Mọi người cho mình hỏi thủ tục làm lại thẻ thư viện mất bao lâu ạ?",
        "Góc học tập ở tầng 2 hôm nay đông quá, mình lên tầng 3 thấy vắng hơn đó.",
        "Mình vừa mượn cuốn 'Sức mạnh của thói quen', sách đọc rất cuốn.",
        "Cho mình hỏi thư viện có cho mang nước uống vào không ạ?",
        "Quy định giữ chỗ mới hay nè, ai mượn mà không lấy đỡ phí.",
        "Có ai đăng ký tham gia Workshop kỹ năng đọc nhanh cuối tuần này không?",
        "Thủ thư hôm nay rất nhiệt tình, cảm ơn thư viện nha.",
        "Mùa thi đến rồi, cắm trại ở thư viện thôi anh em ơi.",
        "Sách văn học ở dãy C đang sắp xếp lại hả mọi người?",
        "Mình bị quá hạn sách 1 ngày thì có sao không ạ, lo quá.",
        "Review nhẹ: Cuốn 'Nhà giả kim' rất đáng đọc để tìm lại động lực nhé.",
        "Wifi thư viện dạo này mạnh ghê, tải tài liệu vèo vèo.",
        "Bạn nào nãy để quên bình nước màu xanh ở bàn số 15 tầng 1 thì xuống lấy nhé.",
        "Cho mình hỏi số lượng sách tối đa được mượn cùng lúc là bao nhiêu cuốn vậy?"
    ];

    $stmtChat = $pdo->prepare("INSERT INTO public_chats (user_id, message, created_at) VALUES (?, ?, ?)");

    $count = 0;
    // Insert 40 random messages over the last 7 days
    for ($i = 0; $i < 40; $i++) {
        $uId = $students[array_rand($students)];
        $msg = $realisticMessages[array_rand($realisticMessages)];
        
        // Random time in the last 7 days to simulate active chat
        $chatTs = time() - rand(0, 7 * 86400); 
        
        $stmtChat->execute([$uId, $msg, date('Y-m-d H:i:s', $chatTs)]);
        $count++;
    }

    echo "Recreated $count realistic and correctly encoded public chat messages.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
