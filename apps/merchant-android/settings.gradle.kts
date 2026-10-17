pluginManagement {
    repositories {
        // 国内网络优先走镜像；服务器出网受限时的经验（见 HANDOVER.md §五）
        maven("https://maven.aliyun.com/repository/google")
        maven("https://maven.aliyun.com/repository/public")
        maven("https://maven.aliyun.com/repository/gradle-plugin")
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        maven("https://maven.aliyun.com/repository/google")
        maven("https://maven.aliyun.com/repository/public")
        maven("https://mvn.getui.com/nexus/content/repositories/releases/") {
            // gtsdk 3.3.16 依赖 com.zxid.sdk:sdk-prod-channel-getui，同样只发布在个推源
            content { includeGroup("com.getui"); includeGroup("com.zxid.sdk") }
        }
        // 个推源被防火墙拦截时的离线兜底：dist/getui-maven-repo 由 fetch 脚本维护
        maven(uri("D:/MyProjects/chunlian-fresh/dist/getui-maven-repo")) {
            content { includeGroup("com.getui"); includeGroup("com.zxid.sdk") }
        }
        google()
        mavenCentral()
    }
}

rootProject.name = "chunlian-merchant"
include(":app")
