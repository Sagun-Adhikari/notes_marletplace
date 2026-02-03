<?php
session_start();

// Redirect to login if not logged in
if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "notes");
if(!$conn){ die("❌ DB Connection failed: ".mysqli_connect_error()); }

$user_id = $_SESSION['user_id'];

// Get current user info
$current_user_query = mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'");
$current_user = mysqli_fetch_assoc($current_user_query);

// ==================== HANDLE NOTE UPLOAD ====================
if(isset($_POST['upload_note'])){
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $price = floatval($_POST['price']);

    if(isset($_FILES['note_file']) && $_FILES['note_file']['error'] == 0){
        $file_name = time().'_'.basename($_FILES['note_file']['name']);
        $target_dir = "uploads/";
        if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $target_file = $target_dir.$file_name;
        
        if(move_uploaded_file($_FILES['note_file']['tmp_name'], $target_file)){
            $demo_path = NULL;
            if(pathinfo($target_file, PATHINFO_EXTENSION) == 'pdf'){
                $demo_path = $target_file; // Using same file, demo restriction handled in frontend
            }
            
            $sql = "INSERT INTO notes (user_id, title, description, category, price, thumbnail, demo_path, status)
                    VALUES ('$user_id', '$title', '$description', '$category', '$price', '$target_file', '$demo_path', 'approved')";
            
            if(mysqli_query($conn, $sql)){
                $success_msg = "✅ Note uploaded successfully!";
            } else {
                $error_msg = "❌ Database Error: ".mysqli_error($conn);
            }
        } else {
            $error_msg = "❌ Failed to upload file.";
        }
    } else {
        $error_msg = "❌ Please select a valid file.";
    }
}

// ==================== HANDLE VOUCHER SUBMISSION ====================
if(isset($_POST['send_voucher'])){
    $note_id = intval($_POST['note_id']);
    
    if(isset($_FILES['voucher']) && $_FILES['voucher']['error'] == 0){
        $voucher_name = time().'_'.basename($_FILES['voucher']['name']);
        $voucher_dir = "vouchers/";
        if(!is_dir($voucher_dir)) mkdir($voucher_dir, 0777, true);
        $voucher_path = $voucher_dir.$voucher_name;
        
        if(move_uploaded_file($_FILES['voucher']['tmp_name'], $voucher_path)){
            // Check if already requested
            $check = mysqli_query($conn, "SELECT id FROM note_requests WHERE note_id='$note_id' AND requester_id='$user_id'");
            
            if(mysqli_num_rows($check) > 0){
                $error_msg = "⚠️ You have already sent a voucher for this note!";
            } else {
                $sql = "INSERT INTO note_requests (note_id, requester_id, voucher_path, status)
                        VALUES ('$note_id', '$user_id', '$voucher_path', 'pending')";
                
                if(mysqli_query($conn, $sql)){
                    $success_msg = "✅ Voucher sent successfully! Waiting for approval.";
                } else {
                    $error_msg = "❌ Failed to send voucher.";
                }
            }
        } else {
            $error_msg = "❌ Failed to upload voucher.";
        }
    } else {
        $error_msg = "❌ Please select a voucher image.";
    }
}

// ==================== HANDLE NOTE DOWNLOAD ====================
if(isset($_GET['download_note'])){
    header('Content-Type: application/json');
    $note_id = intval($_GET['download_note']);
    
    // Check if user is owner OR has approved access
    $note_query = mysqli_query($conn, "SELECT * FROM notes WHERE id='$note_id'");
    $note = mysqli_fetch_assoc($note_query);
    
    $is_owner = ($note['user_id'] == $user_id);
    $access_query = mysqli_query($conn, "SELECT id FROM note_requests WHERE note_id='$note_id' AND requester_id='$user_id' AND status='accepted'");
    $has_access = mysqli_num_rows($access_query) > 0;
    
    if($is_owner || $has_access){
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
            'message' => '❌ Access denied. Please upload payment voucher first.'
        ]);
    }
    exit;
}

// ==================== GET NOTE DETAILS (AJAX) ====================
if(isset($_GET['get_note_detail'])){
    header('Content-Type: application/json');
    $note_id = intval($_GET['get_note_detail']);
    
    $query = "SELECT n.*, u.username FROM notes n 
              JOIN users u ON n.user_id = u.id 
              WHERE n.id = '$note_id'";
    $result = mysqli_query($conn, $query);
    $note = mysqli_fetch_assoc($result);
    
    if($note){
        // Check user's access status
        $is_owner = ($note['user_id'] == $user_id);
        $request_query = mysqli_query($conn, "SELECT status FROM note_requests WHERE note_id='$note_id' AND requester_id='$user_id'");
        $request = mysqli_fetch_assoc($request_query);
        
        $note['is_owner'] = $is_owner;
        $note['request_status'] = $request ? $request['status'] : null;
        
        echo json_encode(['success' => true, 'note' => $note]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Note not found']);
    }
    exit;
}

// ==================== SEARCH & FILTER ====================
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

$query = "SELECT n.*, u.username FROM notes n 
          JOIN users u ON n.user_id = u.id 
          WHERE n.status = 'approved'";

if($search != ''){
    $search = mysqli_real_escape_string($conn, $search);
    $query .= " AND (n.title LIKE '%$search%' OR n.description LIKE '%$search%' OR n.category LIKE '%$search%')";
}

if($category_filter != '' && $category_filter != 'All Categories'){
    $category_filter = mysqli_real_escape_string($conn, $category_filter);
    $query .= " AND n.category = '$category_filter'";
}

switch($sort){
    case 'price_low':
        $query .= " ORDER BY n.price ASC";
        break;
    case 'price_high':
        $query .= " ORDER BY n.price DESC";
        break;
    case 'popular':
        $query .= " ORDER BY n.downloads DESC";
        break;
    default:
        $query .= " ORDER BY n.id DESC";
}

$notes_result = mysqli_query($conn, $query);

// Get categories for filter
$categories_result = mysqli_query($conn, "SELECT DISTINCT category FROM notes WHERE status='approved' ORDER BY category ASC");

// Statistics
$total_notes = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM notes WHERE status='approved'"))['c'];
$active_users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM users"))['c'];
$total_downloads = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(downloads) as c FROM notes"))['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 Notes Marketplace - Buy & Sell Study Notes</title>
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
            <span class="user-welcome">
                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($current_user['username']); ?>
            </span>
            <button onclick="window.location.href='profile.php'" class="btn btn-profile">
                <i class="fas fa-user"></i> My Profile
            </button>
            <button onclick="openUploadPopup()" class="btn btn-upload">
                <i class="fas fa-cloud-upload-alt"></i> Upload Notes
            </button>
            <button onclick="confirmLogout()" class="btn btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </nav>
    </div>
</header>

<!-- ==================== MAIN CONTAINER ==================== -->
<div class="main-container">

    <!-- Success/Error Messages -->
    <?php if(isset($success_msg)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>
    
    <?php if(isset($error_msg)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Section -->
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-book"></i></div>
            <div class="stat-info">
                <h3><?php echo $total_notes; ?></h3>
                <p>Total Notes</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3><?php echo $active_users; ?></h3>
                <p>Active Users</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-download"></i></div>
            <div class="stat-info">
                <h3><?php echo $total_downloads; ?></h3>
                <p>Total Downloads</p>
            </div>
        </div>
    </div>

    <!-- Search & Filter Section -->
    <div class="search-section">
        <form method="GET" class="search-form">
            <div class="search-group">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search notes by title, description, or category..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            
            <div class="filter-group">
                <select name="category" class="select-filter">
                    <option value="">All Categories</option>
                    <?php while($cat = mysqli_fetch_assoc($categories_result)): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo ($category_filter == $cat['category']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                
                <select name="sort" class="select-filter">
                    <option value="newest" <?php echo ($sort == 'newest') ? 'selected' : ''; ?>>Newest First</option>
                    <option value="popular" <?php echo ($sort == 'popular') ? 'selected' : ''; ?>>Most Popular</option>
                    <option value="price_low" <?php echo ($sort == 'price_low') ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_high" <?php echo ($sort == 'price_high') ? 'selected' : ''; ?>>Price: High to Low</option>
                </select>
                
                <button type="submit" class="btn btn-search">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Notes Grid -->
    <div class="notes-grid">
        <?php 
        if(mysqli_num_rows($notes_result) > 0):
            while($note = mysqli_fetch_assoc($notes_result)): 
        ?>
            <div class="note-card" onclick="openNoteDetail(<?php echo $note['id']; ?>)">
                <?php if($note['is_featured']): ?>
                    <div class="featured-badge">
                        <i class="fas fa-star"></i> Featured
                    </div>
                <?php endif; ?>
                
                <div class="note-card-header">
                    <h3 class="note-title"><?php echo htmlspecialchars($note['title']); ?></h3>
                </div>
                
                <div class="note-card-body">
                    <p class="note-description">
                        <?php 
                        $desc = htmlspecialchars($note['description']);
                        echo (strlen($desc) > 120) ? substr($desc, 0, 120).'...' : $desc;
                        ?>
                    </p>
                </div>
                
                <div class="note-card-footer">
                    <div class="note-meta">
                        <span class="meta-item">
                            <i class="fas fa-folder"></i> <?php echo htmlspecialchars($note['category']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($note['username']); ?>
                        </span>
                    </div>
                    <div class="note-info">
                        <span class="price">Rs. <?php echo number_format($note['price'], 2); ?></span>
                        <span class="downloads">
                            <i class="fas fa-download"></i> <?php echo $note['downloads']; ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php 
            endwhile;
        else:
        ?>
            <div class="no-results">
                <i class="fas fa-search"></i>
                <h3>No notes found</h3>
                <p>Try adjusting your search or filters</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ==================== UPLOAD NOTE POPUP ==================== -->
<div id="uploadPopup" class="popup-overlay">
    <div class="popup-container">
        <div class="popup-header">
            <h2><i class="fas fa-cloud-upload-alt"></i> Upload Your Notes</h2>
            <button class="close-btn" onclick="closeUploadPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <div class="form-group">
                <label><i class="fas fa-heading"></i> Title</label>
                <input type="text" name="title" required placeholder="Enter note title">
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-align-left"></i> Description</label>
                <textarea name="description" required rows="4" placeholder="Describe your notes..."></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Category</label>
                    <input type="text" name="category" required placeholder="e.g., Mathematics, Physics">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-dollar-sign"></i> Price (Rs.)</label>
                    <input type="number" name="price" step="0.01" required placeholder="0.00">
                </div>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-file-pdf"></i> Upload File (PDF, DOC, DOCX, PPT, PPTX)</label>
                <div class="file-upload-wrapper">
                    <input type="file" name="note_file" id="noteFile" accept=".pdf,.doc,.docx,.ppt,.pptx" required>
                    <label for="noteFile" class="file-upload-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>Choose File</span>
                    </label>
                    <span class="file-name">No file selected</span>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="upload_note" class="btn btn-success">
                    <i class="fas fa-check"></i> Upload Note
                </button>
                <button type="button" onclick="closeUploadPopup()" class="btn btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== NOTE DETAIL POPUP ==================== -->
<div id="noteDetailPopup" class="popup-overlay">
    <div class="popup-container popup-large">
        <div class="popup-header">
            <h2><i class="fas fa-book-open"></i> Note Details</h2>
            <button class="close-btn" onclick="closeNoteDetailPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="noteDetailContent" class="popup-body">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>
</div>

<!-- ==================== DEMO PREVIEW POPUP ==================== -->
<div id="demoPopup" class="popup-overlay">
    <div class="popup-container popup-large">
        <div class="popup-header">
            <h2><i class="fas fa-eye"></i> Demo Preview (Limited)</h2>
            <button class="close-btn" onclick="closeDemoPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="popup-body">
            <div class="demo-preview-container">
                <iframe id="demoFrame" class="demo-frame"></iframe>
                <div class="demo-overlay">
                    <div class="demo-lock">
                        <i class="fas fa-lock"></i>
                        <h3>Full Content Locked</h3>
                        <p>This is a limited preview. Purchase to unlock full access.</p>
                        <button onclick="unlockFromDemo()" class="btn btn-unlock">
                            <i class="fas fa-unlock"></i> Unlock Full Access
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== VOUCHER UPLOAD POPUP ==================== -->
<div id="voucherPopup" class="popup-overlay">
    <div class="popup-container">
        <div class="popup-header">
            <h2><i class="fas fa-receipt"></i> Upload Payment Voucher</h2>
            <button class="close-btn" onclick="closeVoucherPopup()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="popup-body">
            <div class="voucher-info">
                <i class="fas fa-info-circle"></i>
                <p>Please upload a screenshot of your payment to request download access. The note owner will review and approve your request.</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="voucher-form">
                <input type="hidden" name="note_id" id="voucherNoteId">
                
                <div class="form-group">
                    <label><i class="fas fa-image"></i> Upload Voucher Image</label>
                    <div class="file-upload-wrapper">
                        <input type="file" name="voucher" id="voucherFile" accept="image/*" required>
                        <label for="voucherFile" class="file-upload-label">
                            <i class="fas fa-camera"></i>
                            <span>Choose Image</span>
                        </label>
                        <span class="file-name">No file selected</span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="send_voucher" class="btn btn-success">
                        <i class="fas fa-paper-plane"></i> Send Voucher
                    </button>
                    <button type="button" onclick="closeVoucherPopup()" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== JAVASCRIPT ==================== -->
<script>
// Global variable to store current note ID for demo
let currentNoteIdForDemo = null;

// Logout confirmation
function confirmLogout() {
    if(confirm('Are you sure you want to logout?')) {
        window.location.href = 'login.php';
    }
}

// File input preview
document.getElementById('noteFile')?.addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || 'No file selected';
    this.closest('.file-upload-wrapper').querySelector('.file-name').textContent = fileName;
});

document.getElementById('voucherFile')?.addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || 'No file selected';
    this.closest('.file-upload-wrapper').querySelector('.file-name').textContent = fileName;
});

// ========== UPLOAD POPUP ==========
function openUploadPopup() {
    document.getElementById('uploadPopup').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeUploadPopup() {
    document.getElementById('uploadPopup').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// ========== NOTE DETAIL POPUP ==========
function openNoteDetail(noteId) {
    const popup = document.getElementById('noteDetailPopup');
    const content = document.getElementById('noteDetailContent');
    
    popup.classList.add('active');
    document.body.style.overflow = 'hidden';
    content.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch('?get_note_detail=' + noteId)
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                displayNoteDetail(data.note);
            } else {
                content.innerHTML = '<div class="error-message"><i class="fas fa-exclamation-circle"></i> ' + data.message + '</div>';
            }
        })
        .catch(err => {
            content.innerHTML = '<div class="error-message"><i class="fas fa-exclamation-circle"></i> Error loading note details</div>';
        });
}

function displayNoteDetail(note) {
    const content = document.getElementById('noteDetailContent');
    
    let actionButtons = '';
    
    if(note.is_owner) {
        actionButtons = `
            <div class="note-detail-actions">
                <div class="owner-badge">
                    <i class="fas fa-crown"></i> You own this note
                </div>
                <button onclick="downloadNote(${note.id})" class="btn btn-download">
                    <i class="fas fa-download"></i> Download Your Note
                </button>
            </div>
        `;
    } else if(note.request_status === 'accepted') {
        actionButtons = `
            <div class="note-detail-actions">
                <div class="approved-badge">
                    <i class="fas fa-check-circle"></i> Access Granted
                </div>
                <button onclick="downloadNote(${note.id})" class="btn btn-download">
                    <i class="fas fa-download"></i> Download Note
                </button>
            </div>
        `;
    } else if(note.request_status === 'pending') {
        actionButtons = `
            <div class="note-detail-actions">
                <div class="pending-badge">
                    <i class="fas fa-clock"></i> Voucher Pending Approval
                </div>
                <p class="status-message">Your payment voucher is being reviewed by the note owner.</p>
            </div>
        `;
    } else if(note.request_status === 'rejected') {
        actionButtons = `
            <div class="note-detail-actions">
                <div class="rejected-badge">
                    <i class="fas fa-times-circle"></i> Request Rejected
                </div>
                <p class="status-message">Your voucher was rejected. You can submit a new one.</p>
                <div class="action-buttons">
                    ${note.demo_path ? `<button onclick="openDemo(${note.id}, '${note.demo_path}')" class="btn btn-demo"><i class="fas fa-eye"></i> View Demo</button>` : ''}
                    <button onclick="openVoucherPopup(${note.id})" class="btn btn-primary">
                        <i class="fas fa-shopping-cart"></i> Re-submit Voucher
                    </button>
                </div>
            </div>
        `;
    } else {
        actionButtons = `
            <div class="note-detail-actions">
                <div class="action-buttons">
                    ${note.demo_path ? `<button onclick="openDemo(${note.id}, '${note.demo_path}')" class="btn btn-demo"><i class="fas fa-eye"></i> View Demo</button>` : ''}
                    <button onclick="openVoucherPopup(${note.id})" class="btn btn-primary">
                        <i class="fas fa-shopping-cart"></i> Buy This Note
                    </button>
                </div>
            </div>
        `;
    }
    
    content.innerHTML = `
        <div class="note-detail-card">
            <div class="note-detail-header">
                <h1>${note.title}</h1>
                <span class="note-price">Rs. ${parseFloat(note.price).toFixed(2)}</span>
            </div>
            
            <div class="note-detail-meta">
                <span><i class="fas fa-user"></i> ${note.username}</span>
                <span><i class="fas fa-folder"></i> ${note.category}</span>
                <span><i class="fas fa-download"></i> ${note.downloads} downloads</span>
                <span><i class="fas fa-calendar"></i> ${new Date(note.created_at).toLocaleDateString()}</span>
            </div>
            
            <div class="note-detail-description">
                <h3><i class="fas fa-info-circle"></i> Description</h3>
                <p>${note.description}</p>
            </div>
            
            ${actionButtons}
        </div>
    `;
}

function closeNoteDetailPopup() {
    document.getElementById('noteDetailPopup').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// ========== DEMO POPUP ==========
function openDemo(noteId, demoPath) {
    currentNoteIdForDemo = noteId;
    const popup = document.getElementById('demoPopup');
    const frame = document.getElementById('demoFrame');
    
    // Load only first 2 pages (simulated with page parameter)
    frame.src = demoPath + '#page=1&view=FitH';
    
    popup.classList.add('active');
    closeNoteDetailPopup();
    document.body.style.overflow = 'hidden';
}

function closeDemoPopup() {
    document.getElementById('demoPopup').classList.remove('active');
    document.getElementById('demoFrame').src = '';
    document.body.style.overflow = 'auto';
}

function unlockFromDemo() {
    if(currentNoteIdForDemo) {
        closeDemoPopup();
        openVoucherPopup(currentNoteIdForDemo);
    }
}

// ========== VOUCHER POPUP ==========
function openVoucherPopup(noteId) {
    document.getElementById('voucherNoteId').value = noteId;
    document.getElementById('voucherPopup').classList.add('active');
    closeNoteDetailPopup();
    document.body.style.overflow = 'hidden';
}

function closeVoucherPopup() {
    document.getElementById('voucherPopup').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// ========== DOWNLOAD NOTE ==========
function downloadNote(noteId) {
    fetch('?download_note=' + noteId)
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                // Create temporary link and trigger download
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

// Close popups when clicking outside
document.querySelectorAll('.popup-overlay').forEach(popup => {
    popup.addEventListener('click', function(e) {
        if(e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    });
});

// Auto-hide alerts after 5 seconds
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

/* User Welcome & Logout Button Styles */
.user-welcome {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--white);
    font-weight: 600;
    padding: 0.75rem 1.5rem;
    background: rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-full);
    backdrop-filter: blur(10px);
}

.user-welcome i {
    font-size: 1.5rem;
}

.btn-logout {
    background: rgba(239, 68, 68, 0.2);
    color: var(--white);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(239, 68, 68, 0.5);
}

.btn-logout:hover {
    background: #ef4444;
    border-color: #dc2626;
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(239, 68, 68, 0.3);
}
</style>

</body>
</html>