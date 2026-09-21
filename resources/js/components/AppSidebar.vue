<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    FolderGit2,
    LayoutGrid,
    ListChecks,
    Inbox,
    CalendarClock,
    FileCheck2,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as organizationEdit } from '@/routes/organization';
import { index as skillsIndex } from '@/routes/skills';
import { index as reportIndex } from '@/routes/report';
import { index as schedulesIndex } from '@/routes/schedules';
import { index as tasksIndex } from '@/routes/tasks';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const teamSlug = computed(() => page.props.currentTeam?.slug);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl.value,
            icon: LayoutGrid,
        },
    ];

    // Everything below this point is team-scoped, so it only appears once a
    // team is resolved.
    if (teamSlug.value) {
        items.push(
            {
                title: 'Work',
                href: tasksIndex(teamSlug.value).url,
                icon: Inbox,
            },
            {
                title: 'Calendar',
                href: schedulesIndex(teamSlug.value).url,
                icon: CalendarClock,
            },
            {
                title: 'Skill catalogue',
                href: skillsIndex(teamSlug.value).url,
                icon: ListChecks,
            },
            {
                title: 'Board report',
                href: reportIndex(teamSlug.value).url,
                icon: FileCheck2,
            },
            {
                title: 'Organization',
                href: organizationEdit(teamSlug.value).url,
                icon: Building2,
            },
        );
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
