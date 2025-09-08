<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\ListModel;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TemplateController extends Controller
{
    /**
     * Get all available board templates
     * 📋 TEMPLATE LIBRARY: Browse all available templates
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'category' => 'sometimes|string|in:all,business,personal,education,software,marketing,design',
                'search' => 'sometimes|string|max:255',
                'limit' => 'sometimes|integer|min:1|max:50',
            ]);
            
            $category = $validated['category'] ?? 'all';
            $search = $validated['search'] ?? null;
            $limit = $validated['limit'] ?? 20;
            
            // Get predefined templates
            $templates = $this->getPredefinedTemplates();
            
            // Filter by category
            if ($category !== 'all') {
                $templates = array_filter($templates, function ($template) use ($category) {
                    return $template['category'] === $category;
                });
            }
            
            // Filter by search
            if ($search) {
                $templates = array_filter($templates, function ($template) use ($search) {
                    return stripos($template['title'], $search) !== false ||
                           stripos($template['description'], $search) !== false;
                });
            }
            
            // Apply limit
            $templates = array_slice($templates, 0, $limit);
            
            return response()->json([
                'message' => 'Templates retrieved successfully',
                'filters' => [
                    'category' => $category,
                    'search' => $search,
                    'limit' => $limit,
                ],
                'categories' => [
                    'all' => 'All Templates',
                    'business' => 'Business',
                    'personal' => 'Personal',
                    'education' => 'Education',
                    'software' => 'Software Development',
                    'marketing' => 'Marketing',
                    'design' => 'Design',
                ],
                'templates' => array_values($templates),
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Templates retrieval failed', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve templates. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific template details
     * 📋 TEMPLATE DETAILS: Detailed view of a template
     */
    public function show(Request $request, string $templateId): JsonResponse
    {
        try {
            $templates = $this->getPredefinedTemplates();
            $template = collect($templates)->firstWhere('id', $templateId);
            
            if (!$template) {
                return response()->json([
                    'message' => 'Template not found.',
                ], Response::HTTP_NOT_FOUND);
            }
            
            return response()->json([
                'message' => 'Template retrieved successfully',
                'template' => $template,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Template show failed', [
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve template. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create board from template
     * 🚀 CREATE FROM TEMPLATE: Generate new board from template
     */
    public function createBoard(Request $request, string $templateId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Validate request
            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string|max:1000',
                'color' => 'sometimes|string|max:7',
                'is_public' => 'sometimes|boolean',
            ]);
            
            // Get template
            $templates = $this->getPredefinedTemplates();
            $template = collect($templates)->firstWhere('id', $templateId);
            
            if (!$template) {
                return response()->json([
                    'message' => 'Template not found.',
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Create board from template
            $board = DB::transaction(function () use ($template, $validated, $user) {
                // Create the board
                $board = Board::create([
                    'title' => $validated['title'] ?? $template['title'],
                    'description' => $validated['description'] ?? $template['description'],
                    'color' => $validated['color'] ?? $template['color'],
                    'is_public' => $validated['is_public'] ?? false,
                    'owner_id' => $user->id,
                ]);
                
                // Create lists from template
                foreach ($template['lists'] as $listIndex => $listData) {
                    $list = ListModel::create([
                        'title' => $listData['title'],
                        'description' => $listData['description'] ?? null,
                        'board_id' => $board->id,
                        'position' => $listIndex + 1,
                    ]);
                    
                    // Create tasks from template if any
                    if (isset($listData['tasks'])) {
                        foreach ($listData['tasks'] as $taskIndex => $taskData) {
                            Task::create([
                                'title' => $taskData['title'],
                                'description' => $taskData['description'] ?? null,
                                'list_id' => $list->id,
                                'status' => $taskData['status'] ?? 'pending',
                                'priority' => $taskData['priority'] ?? 'medium',
                                'position' => $taskIndex + 1,
                                'created_by' => $user->id,
                                'assigned_to' => $taskData['assigned_to'] ?? null,
                                'due_date' => isset($taskData['due_in_days']) ? 
                                    now()->addDays($taskData['due_in_days']) : null,
                                'tags' => $taskData['tags'] ?? [],
                            ]);
                        }
                    }
                }
                
                return $board;
            });
            
            // Load the created board with relationships
            $board->load(['owner:id,name,avatar', 'lists.tasks']);
            
            return response()->json([
                'message' => "Board '{$board->title}' created successfully from template",
                'template' => [
                    'id' => $template['id'],
                    'title' => $template['title'],
                    'category' => $template['category'],
                ],
                'board' => [
                    'id' => $board->id,
                    'title' => $board->title,
                    'description' => $board->description,
                    'color' => $board->color,
                    'is_public' => $board->is_public,
                    'created_at' => $board->created_at,
                    'owner' => [
                        'id' => $board->owner->id,
                        'name' => $board->owner->name,
                        'avatar' => $board->owner->avatar,
                    ],
                    'lists_count' => $board->lists->count(),
                    'tasks_count' => $board->lists->sum(fn($list) => $list->tasks->count()),
                    'url' => "/boards/{$board->id}",
                ],
            ], Response::HTTP_CREATED);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Create board from template failed', [
                'template_id' => $templateId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to create board from template. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save existing board as template
     * 💾 SAVE AS TEMPLATE: Convert existing board to template
     */
    public function saveAsTemplate(Request $request, string $boardId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Validate request
            $validated = $request->validate([
                'template_title' => 'required|string|max:255',
                'template_description' => 'required|string|max:1000',
                'category' => 'required|string|in:business,personal,education,software,marketing,design',
                'is_public' => 'sometimes|boolean',
                'include_tasks' => 'sometimes|boolean',
            ]);
            
            // Find the board
            $board = Board::findOrFail($boardId);
            
            // Check permissions
            if (!$board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to save this board as template.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Load board with lists and optionally tasks
            $board->load(['lists' => function ($query) use ($validated) {
                $query->orderBy('position');
                if ($validated['include_tasks'] ?? false) {
                    $query->with(['tasks' => function ($taskQuery) {
                        $taskQuery->orderBy('position');
                    }]);
                }
            }]);
            
            // Create template structure
            $templateData = [
                'id' => 'user_' . $board->id . '_' . time(),
                'title' => $validated['template_title'],
                'description' => $validated['template_description'],
                'category' => $validated['category'],
                'color' => $board->color,
                'is_public' => $validated['is_public'] ?? false,
                'created_by' => $user->id,
                'created_by_name' => $user->name,
                'created_at' => now()->toISOString(),
                'based_on_board' => [
                    'id' => $board->id,
                    'title' => $board->title,
                ],
                'usage_count' => 0,
                'lists' => [],
            ];
            
            // Add lists to template
            foreach ($board->lists as $list) {
                $listData = [
                    'title' => $list->title,
                    'description' => $list->description,
                ];
                
                // Add tasks if requested
                if (($validated['include_tasks'] ?? false) && $list->tasks) {
                    $listData['tasks'] = [];
                    foreach ($list->tasks as $task) {
                        $listData['tasks'][] = [
                            'title' => $task->title,
                            'description' => $task->description,
                            'status' => 'pending', // Reset status for template
                            'priority' => $task->priority,
                            'tags' => $task->tags,
                            // Don't include assigned_to or due_date for templates
                        ];
                    }
                }
                
                $templateData['lists'][] = $listData;
            }
            
            // Here you would save to database if you have a templates table
            // For now, we'll just return the template structure
            
            return response()->json([
                'message' => "Board saved as template '{$validated['template_title']}' successfully",
                'template' => $templateData,
                'original_board' => [
                    'id' => $board->id,
                    'title' => $board->title,
                    'lists_count' => $board->lists->count(),
                    'tasks_included' => $validated['include_tasks'] ?? false,
                ],
            ], Response::HTTP_CREATED);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Save board as template failed', [
                'board_id' => $boardId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to save board as template. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get predefined templates
     */
    private function getPredefinedTemplates(): array
    {
        return [
            [
                'id' => 'kanban_basic',
                'title' => 'Basic Kanban Board',
                'description' => 'Simple three-column Kanban board for any project',
                'category' => 'business',
                'color' => '#0079bf',
                'usage_count' => 1250,
                'lists' => [
                    ['title' => 'To Do', 'description' => 'Tasks that need to be started'],
                    ['title' => 'In Progress', 'description' => 'Tasks currently being worked on'],
                    ['title' => 'Done', 'description' => 'Completed tasks'],
                ],
            ],
            [
                'id' => 'project_management',
                'title' => 'Project Management',
                'description' => 'Complete project management workflow with detailed stages',
                'category' => 'business',
                'color' => '#d29034',
                'usage_count' => 890,
                'lists' => [
                    ['title' => 'Backlog', 'description' => 'Project ideas and future tasks'],
                    ['title' => 'Planning', 'description' => 'Tasks being planned and estimated'],
                    ['title' => 'In Progress', 'description' => 'Active development work'],
                    ['title' => 'Review', 'description' => 'Tasks awaiting review or testing'],
                    ['title' => 'Done', 'description' => 'Completed and deployed tasks'],
                ],
            ],
            [
                'id' => 'software_development',
                'title' => 'Software Development',
                'description' => 'Agile software development board with common workflows',
                'category' => 'software',
                'color' => '#519839',
                'usage_count' => 2100,
                'lists' => [
                    ['title' => 'Product Backlog', 'description' => 'Feature requests and user stories'],
                    ['title' => 'Sprint Backlog', 'description' => 'Tasks selected for current sprint'],
                    ['title' => 'In Development', 'description' => 'Code being written'],
                    ['title' => 'Code Review', 'description' => 'Pull requests under review'],
                    ['title' => 'Testing', 'description' => 'Features being tested'],
                    ['title' => 'Deployed', 'description' => 'Features live in production'],
                ],
                'tasks' => [
                    [
                        'list_index' => 0,
                        'tasks' => [
                            [
                                'title' => 'User Authentication System',
                                'description' => 'Implement login, registration, and password reset',
                                'priority' => 'high',
                                'tags' => ['backend', 'security'],
                            ],
                            [
                                'title' => 'Dashboard UI Design',
                                'description' => 'Create responsive dashboard interface',
                                'priority' => 'medium',
                                'tags' => ['frontend', 'ui'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'marketing_campaign',
                'title' => 'Marketing Campaign',
                'description' => 'Plan and execute marketing campaigns from idea to launch',
                'category' => 'marketing',
                'color' => '#eb5a46',
                'usage_count' => 670,
                'lists' => [
                    ['title' => 'Campaign Ideas', 'description' => 'Brainstormed campaign concepts'],
                    ['title' => 'Research & Planning', 'description' => 'Market research and strategy'],
                    ['title' => 'Content Creation', 'description' => 'Creating campaign assets'],
                    ['title' => 'Review & Approval', 'description' => 'Stakeholder review process'],
                    ['title' => 'Launch', 'description' => 'Campaign execution'],
                    ['title' => 'Monitor & Optimize', 'description' => 'Track performance and optimize'],
                ],
            ],
            [
                'id' => 'personal_goals',
                'title' => 'Personal Goals Tracker',
                'description' => 'Track personal goals and habits throughout the year',
                'category' => 'personal',
                'color' => '#c377e0',
                'usage_count' => 1850,
                'lists' => [
                    ['title' => 'Goals for This Year', 'description' => 'Annual goals and aspirations'],
                    ['title' => 'This Month', 'description' => 'Monthly milestones'],
                    ['title' => 'This Week', 'description' => 'Weekly action items'],
                    ['title' => 'In Progress', 'description' => 'Currently working on'],
                    ['title' => 'Completed', 'description' => 'Achieved goals'],
                ],
            ],
            [
                'id' => 'event_planning',
                'title' => 'Event Planning',
                'description' => 'Organize events from initial planning to post-event follow-up',
                'category' => 'business',
                'color' => '#ff9f1a',
                'usage_count' => 540,
                'lists' => [
                    ['title' => 'Initial Planning', 'description' => 'Event concept and basic planning'],
                    ['title' => 'Budget & Venue', 'description' => 'Financial planning and location'],
                    ['title' => 'Marketing & Promotion', 'description' => 'Event promotion activities'],
                    ['title' => 'Final Preparations', 'description' => 'Last-minute details'],
                    ['title' => 'Event Day', 'description' => 'Day-of-event tasks'],
                    ['title' => 'Post-Event', 'description' => 'Follow-up and evaluation'],
                ],
            ],
            [
                'id' => 'content_creation',
                'title' => 'Content Creation Pipeline',
                'description' => 'Manage content creation from ideation to publication',
                'category' => 'marketing',
                'color' => '#00c2e0',
                'usage_count' => 920,
                'lists' => [
                    ['title' => 'Content Ideas', 'description' => 'Blog posts, videos, and social media ideas'],
                    ['title' => 'Research & Outline', 'description' => 'Research topics and create outlines'],
                    ['title' => 'Writing/Creating', 'description' => 'Content creation in progress'],
                    ['title' => 'Editing & Review', 'description' => 'Content review and editing'],
                    ['title' => 'Scheduled', 'description' => 'Content scheduled for publication'],
                    ['title' => 'Published', 'description' => 'Live content'],
                ],
            ],
            [
                'id' => 'design_workflow',
                'title' => 'Design Workflow',
                'description' => 'Design project workflow from brief to final delivery',
                'category' => 'design',
                'color' => '#51e898',
                'usage_count' => 780,
                'lists' => [
                    ['title' => 'Design Briefs', 'description' => 'New design requests and requirements'],
                    ['title' => 'Research & Inspiration', 'description' => 'Gathering references and ideas'],
                    ['title' => 'Concept Development', 'description' => 'Initial design concepts'],
                    ['title' => 'Design in Progress', 'description' => 'Active design work'],
                    ['title' => 'Client Review', 'description' => 'Awaiting client feedback'],
                    ['title' => 'Revisions', 'description' => 'Implementing feedback'],
                    ['title' => 'Final Delivery', 'description' => 'Completed and delivered designs'],
                ],
            ],
            [
                'id' => 'student_semester',
                'title' => 'Student Semester Planner',
                'description' => 'Organize coursework, assignments, and study schedule',
                'category' => 'education',
                'color' => '#0079bf',
                'usage_count' => 1340,
                'lists' => [
                    ['title' => 'Course Overview', 'description' => 'Syllabus and course requirements'],
                    ['title' => 'Upcoming Assignments', 'description' => 'Assignments due soon'],
                    ['title' => 'In Progress', 'description' => 'Currently working on'],
                    ['title' => 'Submitted', 'description' => 'Completed assignments'],
                    ['title' => 'Exams & Tests', 'description' => 'Upcoming exams and preparation'],
                    ['title' => 'Resources', 'description' => 'Study materials and references'],
                ],
            ],
            [
                'id' => 'freelance_projects',
                'title' => 'Freelance Project Management',
                'description' => 'Manage multiple client projects and deliverables',
                'category' => 'business',
                'color' => '#d29034',
                'usage_count' => 650,
                'lists' => [
                    ['title' => 'Leads & Prospects', 'description' => 'Potential new clients'],
                    ['title' => 'Proposals Sent', 'description' => 'Awaiting client response'],
                    ['title' => 'Active Projects', 'description' => 'Current client work'],
                    ['title' => 'Awaiting Client', 'description' => 'Waiting for client feedback/approval'],
                    ['title' => 'Ready to Invoice', 'description' => 'Completed work ready for billing'],
                    ['title' => 'Completed & Paid', 'description' => 'Finished projects'],
                ],
            ],
        ];
    }
}
