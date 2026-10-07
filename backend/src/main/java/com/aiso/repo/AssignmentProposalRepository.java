package com.aiso.repo;

import com.aiso.domain.AssignmentProposal;
import com.aiso.domain.ProposalStatus;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface AssignmentProposalRepository extends JpaRepository<AssignmentProposal, Long> {
    List<AssignmentProposal> findByStatus(ProposalStatus status);

    List<AssignmentProposal> findByRunId(String runId);
}
