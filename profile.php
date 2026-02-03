<?php
session_start();
$conn = mysqli_connect("localhost", "root", "", "notes");
if(!$conn){ die("❌ DB Connection failed: ".mysqli_connect_error()); }

// Dummy login for testing
if(!isset($_SESSION['user_id'])) $_SESSION['user_id'] = 1;
$user_id = $_SESSION['user_id'];

// ==================== HANDLE VOUCHER APPROVAL/REJECTION ====================
if(isset($_POST['handle_request'])){
    $request_id = intval($_POST['request_id']);
    $action = $_POST['action'];
    
    if($action === 'accept'){
        mysqli_query($conn, "UPDATE note_requests SET status='accepted' WHERE id='$request_id'");
        $success_msg = "✅ Voucher approved successfully!";
    } elseif($action === 'reject'){
        mysqli_query($conn, "UPDATE note_requests SET status='rejected' WHERE id='$request_id'");
        $success_msg = "✅ Voucher rejected.";
    }
}

// ==================== GET USER INFO ====================
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
$user = mysqli_fetch_assoc($user_query);

// ==================== MY SALES (Notes I uploaded + Vouchers received) ====================
$my_notes = mysqli_query($conn, "
    SELECT n.*, COUNT(DISTINCT r.id) as total_requests,
           SUM(CASE WHEN r.status='pending' THEN 1 ELSE 0 END) as pending_requests,
           SUM(CASE WHEN r.status='accepted' THEN 1 ELSE 0 END) as approved_requests
    FROM notes n
    LEFT JOIN note_requests r ON n.id = r.note_id
    WHERE n.user_id = '$user_id'
    GROUP BY n.id
    ORDER BY n.id DESC
");

// Get total sales stats
$sales_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT n.id) as total_notes,
           SUM(n.downloads) as total_downloads,
           COUNT(DISTINCT r.id) as total_requests
    FROM notes n
    LEFT JOIN note_requests r ON n.id = r.note_id
    WHERE n.user_id = '$user_id'
"));

// ==================== MY PURCHASES (Vouchers I sent to others) ====================
$my_purchases = mysqli_query($conn, "
    SELECT r.*, n.title, n.price, n.category, n.thumbnail, u.username as owner_name
    FROM note_requests r
    JOIN notes n ON r.note_id = n.id
    JOIN users u ON n.user_id = u.id
    WHERE r.requester_id = '$user_id'
    ORDER BY r.created_at DESC
");

// Get purchase stats
$purchase_stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) as total_requests,
           SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) as approved,
           SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected
    FROM note_requests
    WHERE requester_id = '$user_id'
"));

// ==================== GET VOUCHERS FOR SPECIFIC NOTE (AJAX) ====================
if(isset($_GET['get_vouchers'])){
    header('Content-Type: application/json');
    $note_id = intval($_GET['get_vouchers']);
    
    $vouchers = [];
    $query = mysqli_query($conn, "
        SELECT r.*, u.username, u.email
        FROM note_requests r
        JOIN users u ON r.requester_id = u.id
        WHERE r.note_id = '$note_id'
        ORDER BY r.created_at DESC
    ");
    
    while($voucher = mysqli_fetch_assoc($query)){
        $vouchers[] = $voucher;
    }
    
    echo json_encode(['success' => true, 'vouchers' => $vouchers]);
    exit;
}

// ==================== DOWNLOAD PURCHASED NOTE (AJAX) ====================
if(isset($_GET['download_purchased'])){
    header('Content-Type: application/json');
    $note_id = intval($_GET['download_purchased']);
    
    // Verify user has approved access
    $check = mysqli_query($conn, "
        SELECT n.thumbnail, n.title 
        FROM note_requests r
        JOIN notes n ON r.note_id = n.id
        WHERE r.note_id = '$note_id' AND r.requester_id = '$user_id' AND r.status = 'accepted'
    ");
    
    if(mysqli_num_rows($check) > 0){
        $note = mysqli_fetch_assoc($check);
        
        // Increment download counter
        mysqli_query($conn, "UPDATE notes SET downloads = downloads + 1 WHERE id='$note_id'");
        
        echo json_encode([
            'success' => true,
            'file_path' => $note['thumbnail'],
            'file_name' => basename($note['thumbnail'])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Access denied or voucher not approved yet.'
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Notes Marketplace</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<!-- ==================== HEADER ==================== -->
<header class="header">
    <div class="header-container">
        <div class="logo">
            <i class="fas fa-book-reader"></i>
            <h1>Notes Marketplace</h1>
        </div>
        <nav class="nav-buttons">
            <button onclick="window.location.href='index.php'" class="btn btn-primary">
                <i class="fas fa-home"></i> Home
            </button>
        </nav>
    </div>
</header>

<!-- ==================== PROFILE CONTAINER ==================== -->
<div class="profile-container">

    <!-- Success/Error Messages -->
    <?php if(isset($success_msg)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar">
            <i class="fas fa-user-circle"></i>
        </div>
        <div class="profile-info">
            <h2><?php echo htmlspecialchars($user['username']); ?></h2>
            <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="tabs-container">
        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchTab('sales')">
                <i class="fas fa-chart-line"></i> My Sales
            </button>
            <button class="tab-btn" onclick="switchTab('purchases')">
                <i class="fas fa-shopping-bag"></i> My Purchases
            </button>
        </div>

        <!-- ==================== MY SALES TAB ==================== -->
        <div id="salesTab" class="tab-content active">
            
            <!-- Sales Statistics -->
            <div class="stats-grid">
                <div class="stat-box">
                    <i class="fas fa-book"></i>
                    <div>
                        <h3><?php echo $sales_stats['total_notes'] ?? 0; ?></h3>
                        <p>Notes Uploaded</p>
                    </div>
                </div>
                <div class="stat-box">
                    <i class="fas fa-download"></i>
                    <div>
                        <h3><?php echo $sales_stats['total_downloads'] ?? 0; ?></h3>
                        <p>Total Downloads</p>
                    </div>
                </div>
                <div class="stat-box">
                    <i class="fas fa-receipt"></i>
                    <div>
                        <h3><?php echo $sales_stats['total_requests'] ?? 0; ?></h3>
                        <p>Voucher Requests</p>
                    </div>
                </div>
            </div>

            <!-- My Notes Grid -->
            <div class="section-header">
                <h2><i class="fas fa-book-open"></i> My Uploaded Notes</h2>
            </div>
            
            <div class="notes-grid">
                <?php 
                if(mysqli_num_rows($my_notes) > 0):
                    while($note = mysqli_fetch_assoc($my_notes)): 
                ?>
                    <div class="profile-note-card">
                        <div class="note-card-header">
                            <h3><?php echo htmlspecialchars($note['title']); ?></h3>
                            <span class="note-status status-<?php echo $note['status']; ?>">
                                <?php echo ucfirst($note['status']); ?>
                            </span>
                        </div>
                        
                        <div class="note-card-body">
                            <p><?php echo (strlen($note['description']) > 100) ? substr(htmlspecialchars($note['description']), 0, 100).'...' : htmlspecialchars($note['description']); ?></p>
                        </div>
                        
                        <div class="note-card-stats">
                            <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($note['category']); ?></span>
                            <span><i class="fas fa-dollar-sign"></i> Rs. <?php echo number_format($note['price'], 2); ?></span>
                            <span><i class="fas fa-download"></i> <?php echo $note['downloads']; ?> downloads</span>
                        </div>
                        
                        <div class="note-card-requests">
                            <div class="request-summary">
                                <span class="badge badge-warning">
                                    <i class="fas fa-clock"></i> <?php echo $note['pending_requests']; ?> Pending
                                </span>
                                <span class="badge badge-success">
                                    <i class="fas fa-check"></i> <?php echo $note['approved_requests']; ?> Approved
                                </span>
                            </div>
                            
                            <?php if($note['total_requests'] > 0): ?>
                                <button onclick="viewVouchers(<?php echo $note['id']; ?>)" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> View Vouchers
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php 
                    endwhile;
                else:
                ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h3>No notes uploaded yet</h3>
                        <p>Start uploading your notes to sell them!</p>
                        <button onclick="window.location.href='index.php'" class="btn btn-primary">
                            <i class="fas fa-cloud-upload-alt"></i> Upload Notes
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ==================== MY PURCHASES TAB ==================== -->
        <div id="purchasesTab" class="tab-content">
            
            <!-- Purchase Statistics -->
            <div class="stats-grid">
                <div class="stat-box">
                    <i class="fas fa-receipt"></i>
                    <div>
                        <h3><?php echo $purchase_stats['total_requests'] ?? 0; ?></h3>
                        <p>Total Requests</p>
                    </div>
                </div>
                <div class="stat-box stat-warning">
                    <i class="fas fa-clock"></i>
                    <div>
                        <h3><?php echo $purchase_stats['pending'] ?? 0; ?></h3>
                        <p>Pending Approval</p>
                    </div>
                </div>
                <div class="stat-box stat-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <h3><?php echo $purchase_stats['approved'] ?? 0; ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
                <div class="stat-box stat-danger">
                    <i class="fas fa-times-circle"></i>
                    <div>
                        <h3><?php echo $purchase_stats['rejected'] ?? 0; ?></h3>
                        <p>Rejected</p>
                    </div>
                </div>
            </div>

            <!-- My Purchase Requests -->
            <div class="section-header">
                <h2><i class="fas fa-shopping-cart"></i> My Purchase Requests</h2>
            </div>
            
            <div class="purchases-grid">
                <?php 
                if(mysqli_num_rows($my_purchases) > 0):
                    while($purchase = mysqli_fetch_assoc($my_purchases)): 
                ?>
                    <div class="purchase-card">
                        <div class="purchase-header">
                            <h3><?php echo htmlspecialchars($purchase['title']); ?></h3>
                            <span class="status-badge status-<?php echo $purchase['status']; ?>">
                                <?php 
                                switch($purchase['status']){
                                    case 'pending':
                                        echo '<i class="fas fa-clock"></i> Pending';
                                        break;
                                    case 'accepted':
                                        echo '<i class="fas fa-check-circle"></i> Approved';
                                        break;
                                    case 'rejected':
                                        echo '<i class="fas fa-times-circle"></i> Rejected';
                                        break;
                                }
                                ?>
                            </span>
                        </div>
                        
                        <div class="purchase-info">
                            <p><i class="fas fa-user"></i> <strong>Owner:</strong> <?php echo htmlspecialchars($purchase['owner_name']); ?></p>
                            <p><i class="fas fa-folder"></i> <strong>Category:</strong> <?php echo htmlspecialchars($purchase['category']); ?></p>
                            <p><i class="fas fa-dollar-sign"></i> <strong>Price:</strong> Rs. <?php echo number_format($purchase['price'], 2); ?></p>
                            <p><i class="fas fa-calendar"></i> <strong>Requested:</strong> <?php echo date('M d, Y', strtotime($purchase['created_at'])); ?></p>
                        </div>
                        
                        <div class="purchase-voucher">
                            <img src="<?php echo htmlspecialchars($purchase['voucher_path']); ?>" alt="Voucher" onclick="viewImage('<?php echo htmlspecialchars($purchase['voucher_path']); ?>')">
                        </div>
                        
                        <div class="purchase-actions">
                            <?php if($purchase['status'] === 'accepted'): ?>
                                <button onclick="downloadPurchasedNote(<?php echo $purchase['note_id']; ?>)" class="btn btn-success">
                                    <i class="fas fa-download"></i> Download Note
                                </button>
                            <?php elseif($purchase['status'] === 'pending'): ?>
                                <div class="status-message">
                                    <i class="fas fa-hourglass-half"></i> Waiting for owner approval...
                                </div>
                            <?php else: ?>
                                <div class="status-message rejected">
                                    <i class="fas fa-exclamation-triangle"></i> Your request was rejected
                                </div>
                                <button onclick="window.location.href='index.php'" class="btn btn-primary">
                                    <i class="fas fa-redo"></i> Try Again
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php 
                    endwhile;
                else:
                ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-bag"></i>
                        <h3>No purchase requests yet</h3>
                        <p>Browse and buy notes from the marketplace!</p>
                        <button onclick="window.location.href='index.php'" class="btn btn-primary">
                            <i class="fas fa-search"></i> Browse Notes
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==================== VOUCHERS POPUP ==================== -->
<div id="vouchersPopup" class="popup-overlay">
    <div class="popup-container popup-large">
        <div class="popup-header">
            <h2><i class="fas fa-receipt"></i> Voucher Requests</h2>
            <button class="close-btn" onclick="closeVouchersPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="vouchersContent" class="popup-body">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i> Loading vouchers...
            </div>
        </div>
    </div>
</div>

<!-- ==================== IMAGE VIEWER POPUP ==================== -->
<div id="imageViewerPopup" class="popup-overlay">
    <div class="popup-container">
        <div class="popup-header">
            <h2><i class="fas fa-image"></i> Voucher Image</h2>
            <button class="close-btn" onclick="closeImageViewer()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="popup-body">
            <img id="viewerImage" src="" alt="Voucher" style="width: 100%; border-radius: 10px;">
        </div>
    </div>
</div>

<!-- ==================== JAVASCRIPT ==================== -->
<script>
// Tab Switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    if(tabName === 'sales') {
        document.getElementById('salesTab').classList.add('active');
        document.querySelector('.tab-btn:first-child').classList.add('active');
    } else {
        document.getElementById('purchasesTab').classList.add('active');
        document.querySelector('.tab-btn:last-child').classList.add('active');
    }
}

// View Vouchers for a Note
function viewVouchers(noteId) {
    const popup = document.getElementById('vouchersPopup');
    const content = document.getElementById('vouchersContent');
    
    popup.classList.add('active');
    document.body.style.overflow = 'hidden';
    content.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading vouchers...</div>';
    
    fetch('?get_vouchers=' + noteId)
        .then(res => res.json())
        .then(data => {
            if(data.success && data.vouchers.length > 0) {
                displayVouchers(data.vouchers);
            } else {
                content.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><h3>No vouchers yet</h3></div>';
            }
        })
        .catch(err => {
            content.innerHTML = '<div class="error-message"><i class="fas fa-exclamation-circle"></i> Error loading vouchers</div>';
        });
}

function displayVouchers(vouchers) {
    const content = document.getElementById('vouchersContent');
    
    let html = '<div class="vouchers-list">';
    
    vouchers.forEach(voucher => {
        let statusClass = 'status-' + voucher.status;
        let actionButtons = '';
        
        if(voucher.status === 'pending') {
            actionButtons = `
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="request_id" value="${voucher.id}">
                    <button type="submit" name="handle_request" value="accept" onclick="this.form.action.value='accept'" class="btn btn-sm btn-success">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button type="submit" name="handle_request" value="reject" onclick="this.form.action.value='reject'" class="btn btn-sm btn-danger">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </form>
            `;
        } else if(voucher.status === 'accepted') {
            actionButtons = '<span class="badge badge-success"><i class="fas fa-check"></i> Approved</span>';
        } else {
            actionButtons = '<span class="badge badge-danger"><i class="fas fa-times"></i> Rejected</span>';
        }
        
        html += `
            <div class="voucher-item ${statusClass}">
                <div class="voucher-header">
                    <div class="voucher-user">
                        <i class="fas fa-user-circle"></i>
                        <div>
                            <strong>${voucher.username}</strong>
                            <small>${voucher.email}</small>
                        </div>
                    </div>
                    <span class="voucher-date">
                        <i class="fas fa-calendar"></i> ${new Date(voucher.created_at).toLocaleDateString()}
                    </span>
                </div>
                
                <div class="voucher-image-container">
                    <img src="${voucher.voucher_path}" alt="Voucher" onclick="viewImage('${voucher.voucher_path}')">
                </div>
                
                <div class="voucher-actions">
                    ${actionButtons}
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    content.innerHTML = html;
}

function closeVouchersPopup() {
    document.getElementById('vouchersPopup').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Image Viewer
function viewImage(imagePath) {
    document.getElementById('viewerImage').src = imagePath;
    document.getElementById('imageViewerPopup').classList.add('active');
}

function closeImageViewer() {
    document.getElementById('imageViewerPopup').classList.remove('active');
}

// Download Purchased Note
function downloadPurchasedNote(noteId) {
    fetch('?download_purchased=' + noteId)
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                const link = document.createElement('a');
                link.href = data.file_path;
                link.download = data.file_name;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                alert('✅ Download started successfully!');
            } else {
                alert(data.message);
            }
        })
        .catch(err => {
            alert('❌ Error downloading file');
        });
}

// Close popups on outside click
document.querySelectorAll('.popup-overlay').forEach(popup => {
    popup.addEventListener('click', function(e) {
        if(e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    });
});

// Auto-hide alerts
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.animation = 'slideOut 0.5s ease-out forwards';
        setTimeout(() => alert.remove(), 500);
    }, 5000);
});
</script>

<style>
@keyframes slideOut {
    to { transform: translateX(120%); opacity: 0; }
}
</style>

</body>
</html>