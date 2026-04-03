const message = document.getElementById("loginMessage");
const emailInput = document.getElementById("email");
const codeInput = document.getElementById("code");

document.getElementById("sendCodeBtn").addEventListener("click", async () => {
    const email = emailInput.value.trim();
    if (!email) {
        message.textContent = "Podaj email.";
        return;
    }

    const response = await fetch("/api/auth/send-code", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({email})
    });

    const data = await response.json();
    message.textContent = data.message;
});

document.getElementById("verifyBtn").addEventListener("click", async () => {
    const email = emailInput.value.trim();
    const code = codeInput.value.trim();
    if (!email || !code) {
        message.textContent = "Podaj email i kod.";
        return;
    }

    const response = await fetch("/api/auth/verify", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({email, code})
    });

    const data = await response.json();
    message.textContent = data.message;
    if (data.success) {
        localStorage.setItem("userEmail", data.email);
        localStorage.setItem("isAdmin", String(data.admin));
        window.location.href = data.admin ? "/dashboard-admin.html" : "/dashboard-user.html";
    }
});
