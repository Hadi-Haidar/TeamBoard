<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The task this attachment belongs to
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The user who uploaded this attachment
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The board this attachment belongs to (through task)
     */
    public function board()
    {
        return $this->hasOneThrough(
            Board::class,
            Task::class,
            'id',
            'id',
            'task_id',
            'list_id'
        )->join('lists', 'tasks.list_id', '=', 'lists.id')
          ->where('lists.board_id', '=', 'boards.id');
    }

    // ==== FILE TYPE HELPERS ====

    /**
     * Check if attachment is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if attachment is a video
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    /**
     * Check if attachment is an audio file
     */
    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type, 'audio/');
    }

    /**
     * Check if attachment is a document
     */
    public function isDocument(): bool
    {
        return in_array($this->mime_type, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
        ]);
    }

    /**
     * Check if attachment is an archive/zip
     */
    public function isArchive(): bool
    {
        return in_array($this->mime_type, [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/gzip',
            'application/x-tar',
        ]);
    }

    /**
     * Check if attachment is a code file
     */
    public function isCode(): bool
    {
        $codeExtensions = ['php', 'js', 'html', 'css', 'py', 'java', 'cpp', 'c', 'rb', 'go'];
        $extension = pathinfo($this->file_name, PATHINFO_EXTENSION);
        
        return in_array(strtolower($extension), $codeExtensions) ||
               str_starts_with($this->mime_type, 'text/');
    }

    /**
     * Get file type category
     */
    public function getFileTypeAttribute(): string
    {
        if ($this->isImage()) return 'image';
        if ($this->isVideo()) return 'video';
        if ($this->isAudio()) return 'audio';
        if ($this->isDocument()) return 'document';
        if ($this->isArchive()) return 'archive';
        if ($this->isCode()) return 'code';
        
        return 'other';
    }

    /**
     * Get file extension
     */
    public function getFileExtensionAttribute(): string
    {
        return strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION));
    }

    /**
     * Get file name without extension
     */
    public function getFileNameWithoutExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_FILENAME);
    }

    // ==== FILE SIZE HELPERS ====

    /**
     * Get human readable file size
     */
    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        
        if ($bytes == 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        
        return sprintf("%.1f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Check if file is large (over X MB)
     */
    public function isLarge(int $mbLimit = 10): bool
    {
        return $this->file_size > ($mbLimit * 1024 * 1024);
    }

    /**
     * Check if file is small (under X KB)
     */
    public function isSmall(int $kbLimit = 100): bool
    {
        return $this->file_size < ($kbLimit * 1024);
    }

    // ==== FILE ACCESS HELPERS ====

    /**
     * Get full file URL
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    /**
     * Get download URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return route('attachments.download', $this->id);
    }

    /**
     * Get thumbnail URL (for images)
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->isImage()) {
            return null;
        }

        // Generate thumbnail path
        $thumbnailPath = 'thumbnails/' . pathinfo($this->file_path, PATHINFO_FILENAME) . '_thumb.jpg';
        
        if (Storage::exists($thumbnailPath)) {
            return Storage::url($thumbnailPath);
        }

        return $this->url; // Fallback to original image
    }

    /**
     * Check if file exists in storage
     */
    public function exists(): bool
    {
        return Storage::exists($this->file_path);
    }

    /**
     * Get file contents
     */
    public function getContents(): string
    {
        if (!$this->exists()) {
            throw new \Exception("File not found: {$this->file_path}");
        }

        return Storage::get($this->file_path);
    }

    /**
     * Download file
     */
    public function download()
    {
        if (!$this->exists()) {
            abort(404, 'File not found');
        }

        return Storage::download($this->file_path, $this->file_name);
    }

    // ==== PERMISSION HELPERS ====

    /**
     * Check if user can view this attachment
     */
    public function canBeViewedBy(User $user): bool
    {
        return $this->task->canBeViewedBy($user);
    }

    /**
     * Check if user can download this attachment
     */
    public function canBeDownloadedBy(User $user): bool
    {
        return $this->task->canBeViewedBy($user);
    }

    /**
     * Check if user can delete this attachment
     */
    public function canBeDeletedBy(User $user): bool
    {
        // User can delete their own attachments, task creator, or board owner
        return $this->user_id === $user->id ||
               $this->task->isCreatedBy($user) ||
               $this->task->list->board->isOwnedBy($user);
    }

    /**
     * Check if user uploaded this attachment
     */
    public function isUploadedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    // ==== FILE OPERATIONS ====

    /**
     * Delete attachment and file
     */
    public function deleteWithFile(): bool
    {
        // Delete file from storage
        if ($this->exists()) {
            Storage::delete($this->file_path);
            
            // Also delete thumbnail if exists
            $thumbnailPath = 'thumbnails/' . pathinfo($this->file_path, PATHINFO_FILENAME) . '_thumb.jpg';
            if (Storage::exists($thumbnailPath)) {
                Storage::delete($thumbnailPath);
            }
        }

        // Delete database record
        return $this->delete();
    }

    /**
     * Duplicate attachment for another task
     */
    public function duplicate(Task $targetTask): self
    {
        // Copy file to new location
        $newFileName = time() . '_' . $this->file_name;
        $newFilePath = 'attachments/' . $targetTask->id . '/' . $newFileName;
        
        Storage::copy($this->file_path, $newFilePath);

        // Create new attachment record
        return self::create([
            'task_id' => $targetTask->id,
            'user_id' => auth()->id(),
            'file_name' => $this->file_name,
            'file_path' => $newFilePath,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
        ]);
    }

    /**
     * Generate thumbnail for images
     */
    public function generateThumbnail(int $width = 300, int $height = 300): ?string
    {
        if (!$this->isImage() || !$this->exists()) {
            return null;
        }

        try {
            $thumbnailPath = 'thumbnails/' . pathinfo($this->file_path, PATHINFO_FILENAME) . '_thumb.jpg';
            
            // You can use Intervention Image or similar library here
            // For now, just return the thumbnail path
            
            return $thumbnailPath;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ==== SCOPES ====

    /**
     * Scope to get attachments by file type
     */
    public function scopeOfType($query, string $type)
    {
        return match($type) {
            'image' => $query->where('mime_type', 'like', 'image/%'),
            'video' => $query->where('mime_type', 'like', 'video/%'),
            'audio' => $query->where('mime_type', 'like', 'audio/%'),
            'document' => $query->whereIn('mime_type', [
                'application/pdf', 'application/msword', 'text/plain'
            ]),
            default => $query
        };
    }

    /**
     * Scope to get images only
     */
    public function scopeImages($query)
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    /**
     * Scope to get documents only
     */
    public function scopeDocuments($query)
    {
        return $query->where(function($q) {
            $q->where('mime_type', 'like', 'application/%')
              ->orWhere('mime_type', 'like', 'text/%');
        });
    }

    /**
     * Scope to get attachments uploaded by user
     */
    public function scopeUploadedBy($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope to get attachments on specific task
     */
    public function scopeOnTask($query, Task $task)
    {
        return $query->where('task_id', $task->id);
    }

    /**
     * Scope to get large files
     */
    public function scopeLarge($query, int $mbLimit = 10)
    {
        return $query->where('file_size', '>', $mbLimit * 1024 * 1024);
    }

    /**
     * Scope to get recent attachments
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to search by filename
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where('file_name', 'like', "%{$search}%");
    }

    /**
     * Scope to get attachments in specific board
     */
    public function scopeInBoard($query, Board $board)
    {
        return $query->whereHas('task.list', function($q) use ($board) {
            $q->where('board_id', $board->id);
        });
    }

    // ==== STATIC METHODS ====

    /**
     * Create attachment from uploaded file
     */
    public static function createFromUpload(UploadedFile $file, Task $task, User $user = null): self
    {
        $user = $user ?? auth()->user();
        
        // Generate unique filename
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('attachments/' . $task->id, $fileName);
        
        return self::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    /**
     * Get total storage used by attachments
     */
    public static function getTotalStorageUsed(): int
    {
        return self::sum('file_size');
    }

    /**
     * Clean up orphaned files (files without database records)
     */
    public static function cleanupOrphanedFiles(): int
    {
        $attachmentPaths = self::pluck('file_path')->toArray();
        $allFiles = Storage::allFiles('attachments');
        
        $orphanedFiles = array_diff($allFiles, $attachmentPaths);
        
        foreach ($orphanedFiles as $file) {
            Storage::delete($file);
        }
        
        return count($orphanedFiles);
    }

    // ==== EVENTS ====

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // When creating attachment, set user_id automatically if not set
        static::creating(function ($attachment) {
            if (is_null($attachment->user_id) && auth()->check()) {
                $attachment->user_id = auth()->id();
            }
        });

        // Log activity when attachment is uploaded
        static::created(function ($attachment) {
            Activity::create([
                'user_id' => $attachment->user_id,
                'board_id' => $attachment->task->list->board_id,
                'subject_type' => Task::class,
                'subject_id' => $attachment->task_id,
                'action' => 'uploaded_file',
                'description' => "Uploaded file '{$attachment->file_name}' to task '{$attachment->task->title}'"
            ]);
        });

        // Clean up file when attachment is deleted
        static::deleting(function ($attachment) {
            if ($attachment->exists()) {
                Storage::delete($attachment->file_path);
                
                // Also delete thumbnail
                $thumbnailPath = 'thumbnails/' . pathinfo($attachment->file_path, PATHINFO_FILENAME) . '_thumb.jpg';
                if (Storage::exists($thumbnailPath)) {
                    Storage::delete($thumbnailPath);
                }
            }
        });
    }
}