package com.aiso.repo;

import com.aiso.domain.AppUser;
import com.aiso.domain.Role;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface UserRepository extends JpaRepository<AppUser, String> {
    long countByRole(Role role);

    List<AppUser> findByRoleIn(List<Role> roles);
}
