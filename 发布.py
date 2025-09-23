import os
import zipfile
import sys

def create_zip_excluding_config_and_self():
    # 获取当前目录
    current_dir = os.getcwd()
    
    # 获取当前目录的名称作为zip文件名
    dir_name = os.path.basename(current_dir)
    zip_filename = f"{dir_name}.zip"
    
    # 定义要排除的文件夹和文件
    exclude_folders = ['config', '.git', 'APKs', 'local_repo']  # 添加了.git目录
    script_name = os.path.basename(__file__)  # 获取当前脚本文件名
    
    # 如果zip文件已存在，先删除
    if os.path.exists(zip_filename):
        os.remove(zip_filename)
    
    # 创建zip文件
    with zipfile.ZipFile(zip_filename, 'w', zipfile.ZIP_DEFLATED) as zipf:
        # 遍历当前目录下的所有文件和文件夹
        for root, dirs, files in os.walk(current_dir):
            # 排除指定的文件夹
            dirs[:] = [d for d in dirs if d not in exclude_folders]
            
            for file in files:
                file_path = os.path.join(root, file)
                
                # 计算在zip文件中的相对路径
                arcname = os.path.relpath(file_path, current_dir)
                
                # 排除当前脚本文件
                if file == script_name:
                    continue
                
                # 排除即将生成的zip文件
                if file == zip_filename:
                    continue
                
                # 排除指定文件夹中的文件（双重保险）
                if any(exclude_folder in file_path.split(os.sep) for exclude_folder in exclude_folders):
                    continue
                
                # 将文件添加到zip中
                zipf.write(file_path, arcname)
                print(f'已添加: {arcname}')
    
    print(f'\n打包完成！文件已保存为: {zip_filename}')
    print(f'文件大小: {os.path.getsize(zip_filename) / 1024:.2f} KB')

if __name__ == '__main__':
    create_zip_excluding_config_and_self()