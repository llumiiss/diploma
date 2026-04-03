const email = localStorage.getItem("userEmail");
const isAdmin = localStorage.getItem("isAdmin") === "true";

if (!email) {
    window.location.href = "/login.html";
}

if (!isAdmin) {
    window.location.href = "/dashboard-user.html";
}

const whoAmI = document.getElementById("whoAmI");
const subscriptionsList = document.getElementById("subscriptionsList");
const usersList = document.getElementById("usersList");
const addSubBtn = document.getElementById("addSubBtn");

whoAmI.textContent = `Zalogowany: ${email} (ADMIN)`;

async function loadSubscriptions() {
    const response = await fetch("/api/subscriptions");
    const items = await response.json();
    subscriptionsList.innerHTML = "";
    items.forEach(item => {
        const li = document.createElement("li");
        li.textContent = `${item.name} - ${item.monthlyCost.toFixed(2)} PLN/mies.`;
        subscriptionsList.appendChild(li);
    });
}

async function loadUsers() {
    const response = await fetch("/api/users");
    const users = await response.json();
    usersList.innerHTML = "";
    users.forEach(user => {
        const li = document.createElement("li");
        li.textContent = `${user.email} (${user.role})`;
        usersList.appendChild(li);
    });
}

addSubBtn.addEventListener("click", async () => {
    const name = document.getElementById("subName").value.trim();
    const cost = Number(document.getElementById("subCost").value);
    if (!name || Number.isNaN(cost)) {
        alert("Podaj nazwe i cene.");
        return;
    }

    await fetch("/api/subscriptions", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({name: name, monthlyCost: cost})
    });

    await loadSubscriptions();
});

document.getElementById("paypalBtn").addEventListener("click", () => {
    alert("Tu podepniemy integracje PayPal w kolejnym kroku.");
});

loadSubscriptions();
loadUsers();
