package com.subsciptions.diploma.web;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.atomic.AtomicLong;

class SubscriptionStore {
    private final AtomicLong subscriptionId = new AtomicLong(2);
    private final AtomicLong userId = new AtomicLong(2);

    private final Set<String> pendingEmails = new HashSet<>();
    private final List<SubscriptionItem> subscriptions = new ArrayList<>();
    private final List<UserItem> users = new ArrayList<>();

    SubscriptionStore() {
        subscriptions.add(new SubscriptionItem(1L, "Netflix", 49.99));
        subscriptions.add(new SubscriptionItem(2L, "Spotify", 29.99));

        users.add(new UserItem(1L, "admin@demo.pl", "ADMIN"));
        users.add(new UserItem(2L, "user@demo.pl", "USER"));
    }

    void rememberPendingEmail(String email) {
        pendingEmails.add(email.toLowerCase());
    }

    boolean isPendingEmail(String email) {
        return pendingEmails.contains(email.toLowerCase());
    }

    boolean isAdmin(String email) {
        return "admin@demo.pl".equalsIgnoreCase(email);
    }

    void addKnownUser(String email) {
        boolean exists = users.stream().anyMatch(user -> user.email().equalsIgnoreCase(email));
        if (!exists) {
            users.add(new UserItem(userId.incrementAndGet(), email, "USER"));
        }
    }

    List<SubscriptionItem> subscriptions() {
        return List.copyOf(subscriptions);
    }

    SubscriptionItem addSubscription(String name, double monthlyCost) {
        SubscriptionItem item = new SubscriptionItem(subscriptionId.incrementAndGet(), name, monthlyCost);
        subscriptions.add(item);
        return item;
    }

    List<UserItem> users() {
        return List.copyOf(users);
    }
}
