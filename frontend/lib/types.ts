export type Role = "OWNER" | "MANAGER" | "USER_1" | "USER_2" | "USER_3";
export type OpStatus =
  | "NOT_READY"
  | "READY"
  | "ASSIGNED"
  | "IN_PROGRESS"
  | "BLOCKED"
  | "COMPLETED"
  | "CANCELLED";
export type Mode = "ALGORITHM" | "LLM";

export const OP_STATUSES: OpStatus[] = [
  "NOT_READY",
  "READY",
  "ASSIGNED",
  "IN_PROGRESS",
  "BLOCKED",
  "COMPLETED",
  "CANCELLED",
];

export interface User {
  id: string;
  fullName: string;
  role: Role;
  messengerId: string | null;
  active: boolean;
  contactInfo: string | null;
  mustChangePassword: boolean;
  createdAt: string;
}

export interface PredView {
  id: string;
  name: string;
  status: OpStatus | null;
  type: "FINISH_TO_START" | "START_TO_START";
  mandatory: boolean;
  satisfied: boolean;
}

export interface Operation {
  id: string;
  name: string;
  projectId: string;
  projectName: string;
  projectRank: number;
  itemId: string | null;
  itemName: string | null;
  resourceId: string;
  resourceName: string;
  responsibleUserId: string | null;
  assignedUserId: string | null;
  assignedUserName: string | null;
  status: OpStatus;
  totalHours: number;
  directHours: number;
  progressPercent: number;
  plannedStart: string | null;
  plannedEnd: string | null;
  projectedStart: string | null;
  projectedEnd: string | null;
  assignedAt: string | null;
  startedAt: string | null;
  completedAt: string | null;
  completionApproved: boolean;
  blockReason: string | null;
  cancelReason: string | null;
  predecessors: PredView[];
  waitingFor: PredView[];
  delayed: boolean;
  delayHours: number;
  late: boolean;
  tailHours: number;
  description: string | null;
}

export interface OpReport {
  id: number;
  userId: string;
  userName: string | null;
  type: string;
  progressPercent: number | null;
  note: string | null;
  createdAt: string;
}

export interface OperationDetail {
  operation: Operation;
  reports: OpReport[];
  successors: PredView[];
}

export interface Progress {
  totalOperations: number;
  totalHours: number;
  completedHours: number;
  progressPercent: number;
  projectedEnd: string | null;
  remainingCriticalHours: number;
}

export interface ResourceLoad {
  id: string;
  name: string;
  capacity: number;
  busy: number;
  readyWaiting: number;
  notReady: number;
  active: boolean;
}

export interface UserLoad {
  userId: string;
  name: string;
  active: boolean;
  assigned: number;
  inProgress: number;
  blocked: number;
  loadHours: number;
  completed: number;
  completedHours: number;
}

export interface UserPerformance {
  userId: string;
  name: string;
  assigned: number;
  inProgress: number;
  blocked: number;
  completed: number;
  completedHours: number;
  onTimeRate: number | null;
  actualToPlanned: number | null;
  lateCount: number;
  blockReports: number;
}

export type StatusCounts = Record<OpStatus, number>;

export interface ManagerDashboard {
  statusCounts: StatusCounts;
  progress: Progress;
  assignmentMode: Mode;
  llmAvailable: boolean;
  llmModel: string;
  pendingProposals: number;
  ready: Operation[];
  blocked: Operation[];
  delayed: Operation[];
  inProgress: Operation[];
  criticalPath: Operation[];
  resources: ResourceLoad[];
  users: UserLoad[];
  recentReports: OpReport[];
  projects: ProjectSummary[];
  schedule: PlanMetrics;
  naiveSchedule: PlanMetrics;
}

export interface AuditEvent {
  eventId: string;
  occurredAt: string;
  actorId: string | null;
  action: string;
  entityType: string;
  entityId: string | null;
  previousValue: string | null;
  newValue: string | null;
  reason: string | null;
}

export interface ImportSummary {
  id: number;
  importedAt: string;
  actorId: string;
  fileName: string | null;
  format: string | null;
  status: string;
  errorCount: number;
  summary: string | null;
}

export interface OwnerDashboard {
  projectId: string | null;
  projectName: string | null;
  systemStatus: "ACTIVE" | "SUSPENDED";
  dataVersion: string | null;
  configurationVersion: number;
  assignmentMode: Mode;
  llmAvailable: boolean;
  llmModel: string;
  messengerPlatform: string;
  requireCompletionApproval: boolean;
  maxActiveTasksPerUser: number;
  usersByRole: Partial<Record<Role, number>>;
  activeUsers: number;
  statusCounts: StatusCounts;
  progress: Progress;
  failedNotifications: number;
  recentImports: ImportSummary[];
  recentAudit: AuditEvent[];
}

export interface Notification {
  id: number;
  kind: string;
  message: string;
  relatedOperationId: string | null;
  createdAt: string;
  readAt: string | null;
  deliveryStatus: string;
  deliveryError: string | null;
}

export interface UserDashboard {
  userId: string;
  name: string;
  statusCounts: StatusCounts;
  activeTasks: Operation[];
  recentCompleted: Operation[];
  performance: UserPerformance;
  unreadNotifications: number;
  notifications: Notification[];
}

export interface Proposal {
  id: number;
  runId: string;
  operationId: string;
  operationName: string | null;
  projectName: string | null;
  projectRank: number;
  resourceName: string | null;
  userId: string;
  userName: string;
  source: Mode;
  reason: string | null;
  plannedStart: string | null;
  plannedEnd: string | null;
  status: "PENDING" | "APPROVED" | "REJECTED" | "SUPERSEDED";
  decisionNote: string | null;
  hours: number;
  tailHours: number;
}

export interface Skipped {
  operationId: string;
  operationName: string;
  reason: string;
}

export interface Run {
  id: string;
  requestedMode: Mode;
  effectiveMode: Mode;
  fallback: boolean;
  model: string | null;
  summary: string | null;
  warnings: string[];
  unassigned: Skipped[];
  proposals: Proposal[];
  createdAt: string;
  durationMs: number;
  inputTokens: number | null;
  outputTokens: number | null;
  requestedBy: string;
}

export interface Settings {
  projectId: string | null;
  projectName: string | null;
  ownerId: string | null;
  managerId: string | null;
  messengerPlatform: string;
  systemStatus: "ACTIVE" | "SUSPENDED";
  dataVersion: string | null;
  configurationVersion: number;
  assignmentMode: Mode;
  maxActiveTasksPerUser: number;
  requireCompletionApproval: boolean;
  llmAvailable: boolean;
  llmModel: string;
  language: "fa" | "en";
}

export interface ImportIssue {
  sheet: string;
  row: number;
  column: string;
  message: string;
}

export interface ProjectRef {
  id: string;
  name: string;
  priority: number;
}

export interface ImportResult {
  status: "REJECTED" | "VALID" | "APPLIED" | "PRIORITY_REQUIRED";
  format: string;
  projectId: string | null;
  projectName: string | null;
  newProject: boolean;
  activeProjects: ProjectRef[];
  impact: Impact | null;
  errors: ImportIssue[];
  warnings: string[];
  counts: { entity: string; created: number; updated: number }[];
  newUsers: { userId: string; fullName: string; temporaryPassword: string }[];
}

export interface ImportLog {
  id: number;
  importedAt: string;
  actorId: string;
  fileName: string | null;
  format: string | null;
  status: string;
  errorCount: number;
  summary: string | null;
  details: string | null;
}

// ---- multiple projects & schedule ----------------------------------------------------------------------

export interface ProjectSummary {
  id: string;
  name: string;
  priority: number;
  status: "ACTIVE" | "ARCHIVED";
  dueDate: string | null;
  createdAt: string;
  totalOperations: number;
  statusCounts: StatusCounts;
  totalHours: number;
  completedHours: number;
  progressPercent: number;
  openOperations: number;
  finishHours: number;
  finishAt: string | null;
  tardinessHours: number;
}

export interface ProjectPlan {
  projectId: string;
  name: string;
  rank: number;
  openOperations: number;
  startHours: number;
  finishHours: number;
  finishAt: string | null;
  dueDate: string | null;
  tardinessHours: number;
  rule: string | null;
}

export interface ResourceMetric {
  resourceId: string;
  name: string;
  capacity: number;
  busyHours: number;
  wasteHours: number;
  utilizationPercent: number;
}

export interface PlanMetrics {
  makespanHours: number;
  finishAt: string | null;
  busyHours: number;
  wasteHours: number;
  utilizationPercent: number;
  projects: ProjectPlan[];
  resources: ResourceMetric[];
  candidatesTried: number;
}

export interface ProjectDelta {
  projectId: string;
  name: string;
  currentRank: number | null;
  proposedRank: number;
  currentFinishHours: number | null;
  proposedFinishHours: number;
  deltaHours: number | null;
}

export interface Impact {
  current: PlanMetrics;
  proposed: PlanMetrics;
  naive: PlanMetrics;
  deltas: ProjectDelta[];
  wasteSavedVsNaive: number;
  makespanSavedVsNaive: number;
}

export interface SchedulingOverview {
  optimized: PlanMetrics;
  naive: PlanMetrics;
  projects: ProjectSummary[];
}
