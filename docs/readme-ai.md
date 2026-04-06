# README-AI

## Purpose

This document defines the AI direction for Procynia as an extension of the existing bid management system. The goal is to build an AI-supported bid execution layer that improves real-world tender outcomes: higher win rates, lower risk, faster throughput, and full traceability.

Procynia AI is not a chatbot. It is an AI-supported bid case engine.

---

## Product Definition

Procynia AI turns a tender into a structured, operable case.

Input:
Tender documents, attachments, and requirements.

Output:
A structured bid case with full requirement coverage, ownership, evidence, drafts, risks, and delivery readiness.

The system must always answer:
What is required?
What is done?
What is missing?
What is risky?
Who is responsible?
What supports each claim?

---

## Core Principle

AI supports structure. It does not replace it.

All AI output must be:
Grounded in sources
Traceable to documents
Separated into facts, inferences, and suggestions

---

## Canonical Data Model (Core Objects)

All AI functionality must build on these objects:

BidCase (SavedNotice)
TenderDocument
Requirement
Criterion
Deadline
AttachmentRequirement
Evidence
ResponseDraft
Gap
Risk
Assignment
ReviewDecision
SubmissionPackage

These objects live in PostgreSQL and represent the single source of truth.

---

## System Architecture (Practical, Local-First)

### Database

PostgreSQL (primary)
pgvector (embeddings inside Postgres)

### Storage

Local filesystem initially
Optional object storage later

### Processing

Background jobs for parsing and extraction

### AI Usage

External APIs only where necessary
Minimize cost and dependency

---

## Dual Data Representation

The system must maintain two parallel representations:

### 1. Structured (Primary)

Stored in PostgreSQL
Used for:
Compliance
Workflow
Risk
Ownership

### 2. Unstructured (Secondary)

Chunks + embeddings
Used for:
Search
Similarity
Content reuse

Structured always wins over unstructured.

---

## Product Strategy

### 1. Compliance-first

System ensures full requirement coverage and flags risk.

### 2. Evidence-first

All claims must link to real sources.

### 3. Team-first

System supports real roles:
Commercial owner
Bid manager
Contributors

### 4. Traceability-first

Every output must show origin and confidence.

---

## Development Phases

### Phase 1: Bid Case Structuring (Foundation)

Goal:
Turn documents into structured data.

Build:
Document upload and parsing
Requirement extraction
Deadline extraction
Attachment extraction

Output:
Structured Requirement list per BidCase

Success Criteria:
All requirements visible and linked to source

---

### Phase 2: Compliance & Gap Detection

Goal:
Understand completeness and risk.

Build:
Compliance matrix
Gap detection
Risk flags

Output:
Clear overview of missing and risky areas

Success Criteria:
System can say what is incomplete before writing anything

---

### Phase 3: Evidence Layer

Goal:
Connect internal knowledge to requirements.

Build:
Store previous content
Store CVs and references
Link evidence to requirements

Output:
Each requirement has potential supporting evidence

Success Criteria:
System suggests relevant material per requirement

---

### Phase 4: Draft Generation

Goal:
Generate controlled first drafts.

Build:
Draft per requirement
Evidence-backed generation
Missing info detection

Output:
Structured drafts with sources and gaps

Success Criteria:
Drafts are usable, not generic

---

### Phase 5: Workflow & Ownership

Goal:
Support real bid execution.

Build:
Assignment per requirement
Status tracking
Review flow

Output:
Full operational overview of the bid

Success Criteria:
Team can run entire bid inside Procynia

---

### Phase 6: Final Assembly

Goal:
Prepare submission.

Build:
Document assembly
Attachment checklist
Submission validation

Output:
Ready-to-submit package

Success Criteria:
No missing mandatory elements

---

### Phase 7: Chat (Optional Layer)

Goal:
Provide natural interface.

Chat must:
Use structured data
Show sources
Never replace core workflow

---

## Key Rules

Do not build chat first
Do not rely only on embeddings
Do not generate text without evidence
Do not mix inferred and factual data

Always:
Prioritize structure
Expose gaps
Show sources

---

## Competitive Advantage

Procynia wins by:
Better structure
Better control
Better workflow
Better traceability

Not by:
Better wording
Bigger models
More AI hype

---

## Summary

Procynia AI = Bid Case Engine + AI Support

The system must:
Structure the bid
Control the process
Support the team
Ensure compliance
Generate grounded drafts

If this is achieved, Procynia will outperform tools that focus only on document chat or text generation.
